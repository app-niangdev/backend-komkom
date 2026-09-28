<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use App\Models\ApplicationSetting;
use Illuminate\Support\Facades\Log;

class FileValidationService
{
    protected int $maxFileSize;

    protected const ALLOWED_IMAGE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
        'image/svg+xml',
        'image/tiff',
        'image/x-icon',
        'image/vnd.microsoft.icon',
        'image/avif',
    ];

    public function __construct()
    {
        $this->maxFileSize = (int) (ApplicationSetting::first()->image_size ?? 2);
    }

    /**
     * Valide et enregistre un fichier directement dans la collection du modèle
     *
     * @param UploadedFile $file
     * @param \Illuminate\Database\Eloquent\Model $model Le modèle Eloquent (Product, Company, etc.)
     * @param string $collection La collection Spatie ('image', 'logo', etc.)
     * @param string $type 'image' ou 'file'
     * @param array|null $customRules
     * @return void
     * @throws ValidationException
     */
    public function validateAndStoreFile(
        UploadedFile $file,
        $model,
        string $collection,
        string $type = 'image',
        ?array $customRules = null
    ): void {
        // Types MIME autorisés
        $imageMimes = 'jpeg,png,jpg,gif,webp,bmp,svg,tiff,ico,avif';
        $fileMimes  = 'pdf,doc,docx,xls,xlsx,csv,zip,rar,txt,ppt,pptx';

        // Règles de validation selon le type
        $rules = match ($type) {
            'image' => ['required', 'image', "mimes:$imageMimes", 'max:' . ($this->maxFileSize * 1024)],
            'file'  => ['required', 'file', "mimes:$fileMimes", 'max:' . ($this->maxFileSize * 1024)],
            default => ['required', 'file', 'max:' . ($this->maxFileSize * 1024)],
        };

        // Ajouter des règles personnalisées si fournies
        if ($customRules) {
            $rules = array_merge($rules, $customRules);
        }

        // Messages personnalisés en français
        $messages = [
            'required' => 'Le fichier est obligatoire.',
            'image'    => 'Le fichier doit être une image valide.',
            'file'     => 'Le fichier fourni est invalide.',
            'mimes'    => 'Le fichier doit être de type : :values.',
            'max'      => "La taille du fichier ne doit pas dépasser {$this->maxFileSize} Mo.",
        ];

        // Validation
        $validator = Validator::make(['file' => $file], ['file' => $rules], $messages);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        // Stockage direct dans la collection du modèle (Spatie Media Library)
        $model->addMedia($file)->toMediaCollection($collection);
    }

    /**
     * Retourne la taille maximale en octets
     *
     * @return int
     */
    public function getMaxFileSizeInBytes(): int
    {
        return $this->maxFileSize * 1024 * 1024;
    }

    /**
     * Télécharge une image depuis une URL, la valide, puis l'enregistre
     * directement dans la collection du modèle (Spatie Media Library).
     *
     * @param string $url
     * @param \Illuminate\Database\Eloquent\Model $model Le modèle Eloquent (Product, Company, etc.)
     * @param string $collection La collection Spatie ('image', 'logo', etc.)
     * @return void
     * @throws ValidationException
     */
    public function validateAndStoreFileFromUrl(string $url, $model, string $collection): void
    {
        $validator = Validator::make(
            ['image_url' => $url],
            ['image_url' => ['required', 'url', 'max:2048']],
            ['image_url.url' => "Le lien de l'image n'est pas valide."]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        try {
            $response = Http::timeout(15)->get($url);
        } catch (\Throwable $e) {
            $this->failWithMessage("Impossible de télécharger l'image depuis le lien fourni.");
        }

        if (!$response->successful()) {
            $this->failWithMessage("Impossible de télécharger l'image depuis le lien fourni.");
        }

        $contentType = strtolower(explode(';', $response->header('Content-Type') ?? '')[0]);
        if (!in_array($contentType, self::ALLOWED_IMAGE_MIME_TYPES, true)) {
            $this->failWithMessage("Le lien fourni ne pointe pas vers une image valide.");
        }

        $bytes = $response->body();
        if (strlen($bytes) > $this->getMaxFileSizeInBytes()) {
            $this->failWithMessage("La taille de l'image ne doit pas dépasser {$this->maxFileSize} Mo.");
        }

        $model->addMediaFromString($bytes)
            ->usingFileName($this->guessFileName($url, $contentType))
            ->toMediaCollection($collection);
    }

    private function guessFileName(string $url, string $contentType): string
    {
        $extensionMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            'image/svg+xml' => 'svg',
            'image/tiff' => 'tiff',
            'image/x-icon' => 'ico',
            'image/vnd.microsoft.icon' => 'ico',
            'image/avif' => 'avif',
        ];

        $extension = $extensionMap[$contentType] ?? pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'jpg';

        return 'image-' . uniqid() . '.' . $extension;
    }

    private function failWithMessage(string $message): never
    {
        $validator = Validator::make([], []);
        $validator->errors()->add('image_url', $message);

        throw new ValidationException($validator);
    }
}
