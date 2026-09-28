<?php

namespace App\Http\Requests;

use App\Models\Store;
use App\Services\CompanyStoreResolverService;
use Illuminate\Foundation\Http\FormRequest;

class ProductStoreRequest extends FormRequest
{
    private ?Store $resolvedStore = null;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('unit_of_measures') && is_string($this->input('unit_of_measures'))) {
            $this->merge([
                'unit_of_measures' => json_decode($this->input('unit_of_measures'), true),
            ]);
        }
    }

    public function rules(): array
    {
        $usesMeasurements = $this->usesMeasurements();

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'description' => ['nullable', 'string'],
            'require_serial_number' => ['nullable', 'boolean'],
            'alert_threshold' => ['nullable', 'numeric', 'min:0'],
            'image_url' => ['nullable', 'url', 'max:2048'],
        ];

        if ($usesMeasurements) {
            $rules = array_merge($rules, [
                'base_unit' => ['required', 'string', 'max:50'],
                'unit_of_measures' => ['required', 'array', 'min:1'],
                'unit_of_measures.*.name' => ['required', 'string', 'max:50'],
                'unit_of_measures.*.price' => ['required', 'numeric', 'min:0'],
                'unit_of_measures.*.conversion_factor' => ['required', 'numeric', 'min:0.001'],
                'unit_of_measures.*.is_base_unit' => ['nullable', 'boolean'],
                'unit_of_measures.*.barcode' => ['nullable', 'string', 'max:255'],
            ]);
        } else {
            $rules = array_merge($rules, [
                'base_unit' => ['nullable', 'string', 'max:50'],
                'unit_of_measures' => ['nullable', 'array'],
                'unit_price' => ['nullable', 'numeric', 'min:0'],
            ]);
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Le nom du produit est obligatoire.',
            'name.max' => 'Le nom du produit ne peut pas dépasser 255 caractères.',
            'category_id.exists' => 'La catégorie sélectionnée est invalide.',
            'base_unit.required' => 'L\'unité de base est obligatoire.',
            'unit_of_measures.required' => 'Au moins une unité de mesure est requise.',
            'unit_of_measures.array' => 'Les unités de mesure doivent être fournies sous forme de tableau.',
            'unit_of_measures.min' => 'Au moins une unité de mesure est requise.',
            'unit_of_measures.*.name.required' => 'Le nom de l\'unité de mesure est obligatoire.',
            'unit_of_measures.*.price.required' => 'Le prix de l\'unité de mesure est obligatoire.',
            'unit_of_measures.*.price.numeric' => 'Le prix doit être un nombre.',
            'unit_of_measures.*.price.min' => 'Le prix ne peut pas être négatif.',
            'unit_of_measures.*.conversion_factor.required' => 'Le facteur de conversion est obligatoire.',
            'unit_of_measures.*.conversion_factor.numeric' => 'Le facteur de conversion doit être un nombre.',
            'unit_of_measures.*.conversion_factor.min' => 'Le facteur de conversion doit être supérieur à 0.',
            'unit_price.numeric' => 'Le prix doit être un nombre.',
            'unit_price.min' => 'Le prix ne peut pas être négatif.',
            'image_url.url' => "Le lien de l'image n'est pas valide.",
        ];
    }

    private function usesMeasurements(): bool
    {
        return $this->resolveStore()?->uses_measurements ?? true;
    }

    public function resolveStore(): ?Store
    {
        if ($this->resolvedStore) {
            return $this->resolvedStore;
        }

        /** @var CompanyStoreResolverService $resolver */
        $resolver = app(CompanyStoreResolverService::class);
        $context = $resolver->resolveStoreAndCompany($this);

        if (empty($context['store_id'])) {
            return null;
        }

        $this->resolvedStore = Store::find($context['store_id']);

        return $this->resolvedStore;
    }
}
