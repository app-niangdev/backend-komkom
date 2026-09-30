<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\FileValidationService;
use App\Services\OwnerScopeService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * « Mes boutiques » : le propriétaire modifie les informations de ses boutiques
 * (coordonnées, identité visuelle, réglages). Le rattachement à l'entreprise,
 * l'activation et la vitrine restent du ressort de l'administrateur.
 */
class OwnerStoreController extends Controller
{
    public function __construct(protected OwnerScopeService $scope)
    {
    }

    public function show(Request $request, int $id)
    {
        return response()->json(['data' => $this->payload($this->ownedStore($request, $id))]);
    }

    public function update(Request $request, int $id, FileValidationService $fileValidator)
    {
        $store = $this->ownedStore($request, $id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slogan' => 'nullable|string|max:255',
            'address' => 'required|string|max:255',
            'phone_one' => 'required|string|max:20',
            'phone_two' => 'nullable|string|max:20',
            'phone_three' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'uses_measurements' => 'sometimes|boolean',
            'uses_serial_numbers' => 'sometimes|boolean',
            'ticket_width' => 'sometimes|in:' . implode(',', Store::TICKET_WIDTHS),
            'use_company_logo' => 'sometimes|boolean',
            'use_company_colors' => 'sometimes|boolean',
            'primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,webp,svg|max:2048',
        ], [
            'name.required' => 'Le nom de la boutique est obligatoire.',
            'address.required' => 'L\'adresse est obligatoire.',
            'phone_one.required' => 'Le téléphone principal est obligatoire.',
            'email.email' => 'L\'email n\'est pas valide.',
            'ticket_width.in' => 'Largeur de ticket non prise en charge (58 ou 80 mm).',
            'primary_color.regex' => 'La couleur principale doit être au format #RRGGBB.',
            'secondary_color.regex' => 'La couleur secondaire doit être au format #RRGGBB.',
            'logo.image' => 'Le logo doit être une image.',
            'logo.mimes' => 'Le logo doit être au format JPG, PNG, WEBP ou SVG.',
            'logo.max' => 'Le logo ne doit pas dépasser 2 Mo.',
        ]);

        if ($request->has('uses_serial_numbers') && !$request->boolean('uses_serial_numbers')
            && $store->uses_serial_numbers && $store->hasSerialProducts()) {
            throw ValidationException::withMessages(['uses_serial_numbers' => Store::SERIALS_IN_USE_MESSAGE]);
        }

        $useCompanyColors = $request->boolean('use_company_colors', (bool) $store->use_company_colors);
        $data = collect($validated)->except('logo')->all();
        // Couleurs de l'entreprise : les couleurs propres à la boutique sont effacées
        if ($useCompanyColors) {
            $data['primary_color'] = null;
            $data['secondary_color'] = null;
        }
        $store->update($data);

        if ($request->hasFile('logo')) {
            $fileValidator->validateAndStoreFile($request->file('logo'), $store, 'logo');
        }

        return response()->json([
            'success' => true,
            'message' => 'Boutique « ' . $store->name . ' » mise à jour.',
            'data' => $this->payload($store->fresh()),
        ]);
    }

    /** Champs du formulaire (même forme que le formulaire boutique de l'administrateur). */
    private function payload(Store $store): array
    {
        return [
            'id' => $store->id,
            'company_id' => $store->company_id,
            'name' => $store->name,
            'slogan' => $store->slogan,
            'address' => $store->address,
            'phone_one' => $store->phone_one,
            'phone_two' => $store->phone_two,
            'phone_three' => $store->phone_three,
            'email' => $store->email,
            'active' => (bool) $store->active,
            'uses_measurements' => (bool) ($store->uses_measurements ?? true),
            'uses_serial_numbers' => (bool) ($store->uses_serial_numbers ?? true),
            'ticket_width' => (int) ($store->ticket_width ?? 80),
            'use_company_logo' => (bool) $store->use_company_logo,
            'use_company_colors' => (bool) $store->use_company_colors,
            'primary_color' => $store->primary_color,
            'secondary_color' => $store->secondary_color,
            'logo_url' => $store->logo_url,
        ];
    }

    private function ownedStore(Request $request, int $id): Store
    {
        $store = $this->scope->stores($request->user())->firstWhere('id', $id);
        abort_if(!$store, 404, 'Boutique introuvable.');

        return $store;
    }
}
