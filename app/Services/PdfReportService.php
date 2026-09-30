<?php

namespace App\Services;

use App\Models\Store;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rapports PDF de l'espace boutique (ventes, encaissements) : en-tête de l'émetteur,
 * période, auteur et numérotation des pages communs à tous les rapports.
 */
class PdfReportService
{
    /** Au-delà, le PDF devient illisible et lent : on renvoie vers l'export CSV. */
    public const MAX_ROWS = 1500;

    /**
     * Émetteur du rapport : la boutique choisie, ou l'entreprise pour « Toutes les boutiques ».
     *
     * @param Collection<int, Store> $stores boutiques incluses
     */
    public function issuer(Collection $stores, ?Store $selected): array
    {
        $store = $selected ?? ($stores->count() === 1 ? $stores->first() : null);
        $company = $store?->company ?? $stores->first()?->company;

        if ($store) {
            return [
                'name' => $store->name,
                'company' => $company?->name,
                'address' => $store->address,
                'phones' => array_values(array_filter([$store->phone_one, $store->phone_two])),
                'email' => $store->email,
                'logo' => $this->storeLogo($store),
                'color' => $store->effective_primary_color ?: '#1f2937',
                'scope' => $store->name,
            ];
        }

        return [
            'name' => $company?->name ?? 'Toutes les boutiques',
            'company' => null,
            'address' => $company?->head_office_address,
            'phones' => array_values(array_filter([$company?->phone_one, $company?->phone_two])),
            'email' => $company?->email,
            'logo' => $this->dataUri($company?->getFirstMedia('logo')?->getPath()),
            'color' => $company?->primary_color ?: '#1f2937',
            'scope' => 'Toutes les boutiques (' . $stores->pluck('name')->implode(', ') . ')',
        ];
    }

    /** « Aujourd'hui, vendredi 25 septembre 2026 » ou « du 01/09/2026 au 25/09/2026 ». */
    public function periodLabel(string $start, string $end): string
    {
        $from = CarbonImmutable::parse($start)->locale('fr');
        $to = CarbonImmutable::parse($end)->locale('fr');

        if ($from->isSameDay($to)) {
            $day = $from->isoFormat('dddd D MMMM YYYY');
            return $from->isToday() ? "Aujourd'hui, {$day}" : ($from->isYesterday() ? "Hier, {$day}" : ucfirst($day));
        }

        return 'Du ' . $from->format('d/m/Y') . ' au ' . $to->format('d/m/Y') . ' (' . ($from->diffInDays($to) + 1) . ' jours)';
    }

    public function download(string $view, array $data, string $filename, User $author, string $orientation = 'portrait'): Response
    {
        $pdf = Pdf::loadView($view, $data + [
            'generatedAt' => now()->locale('fr'),
            'author' => trim($author->first_name . ' ' . $author->last_name),
            'maxRows' => self::MAX_ROWS,
        ])
            ->setPaper('a4', $orientation)
            // Seuls les caractères utilisés de la police sont intégrés : fichier bien plus léger
            ->setOption('isFontSubsettingEnabled', true);

        // Numéro de page en pied (dompdf : rendu après la mise en page)
        $pdf->render();
        $canvas = $pdf->getDomPDF()->getCanvas();
        $font = $pdf->getDomPDF()->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text(
            $canvas->get_width() - 110,
            $canvas->get_height() - 28,
            'Page {PAGE_NUM} / {PAGE_COUNT}',
            $font,
            8,
            [0.42, 0.44, 0.52]
        );

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /** Logo de la boutique (ou de son entreprise) en data URI, prêt pour dompdf. */
    public function storeLogo(Store $store): ?string
    {
        $media = $store->use_company_logo ? null : $store->getFirstMedia('logo');
        $media ??= $store->company?->getFirstMedia('logo');

        return $this->dataUri($media?->getPath());
    }

    /** Logo en data URI, réduit à 200 px (il s'affiche en 13 mm) pour ne pas alourdir le PDF. */
    private function dataUri(?string $path): ?string
    {
        if (!$path || !is_file($path) || filesize($path) > 5 * 1024 * 1024) {
            return null;
        }
        $mime = mime_content_type($path) ?: '';
        if (!str_starts_with($mime, 'image/') || $mime === 'image/svg+xml') {
            return null;
        }

        $bytes = file_get_contents($path);
        if (function_exists('imagecreatefromstring') && ($image = @imagecreatefromstring($bytes))) {
            $width = imagesx($image);
            $height = imagesy($image);
            $scale = min(1, 200 / max($width, $height));
            $resized = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, imagesx($resized), imagesy($resized), $width, $height);
            ob_start();
            imagepng($resized, null, 9);
            $bytes = ob_get_clean();
            $mime = 'image/png';
        }

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }
}
