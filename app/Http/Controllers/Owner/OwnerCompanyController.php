<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Services\FileValidationService;
use App\Services\OwnerScopeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * « Mon entreprise » : l'entreprise du propriétaire connecté, sans identifiant côté client.
 */
class OwnerCompanyController extends Controller
{
    public function __construct(protected OwnerScopeService $scope)
    {
    }

    public function show(Request $request)
    {
        return response()->json(['data' => $this->payload($this->scope->company($request->user()))]);
    }

    public function update(Request $request, FileValidationService $fileValidator)
    {
        $company = $this->scope->company($request->user());

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'short_name' => 'required|string|max:50',
            'slogan' => 'nullable|string|max:255',
            'head_office_address' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('companies', 'email')->ignore($company->id)],
            'phone_one' => 'required|string|max:20',
            'phone_two' => 'nullable|string|max:20',
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'remove_logo' => 'nullable|boolean',
        ], [
            'name.required' => 'La raison sociale est obligatoire.',
            'short_name.required' => 'Le nom abrégé est obligatoire.',
            'head_office_address.required' => 'L\'adresse du siège est obligatoire.',
            'email.required' => 'L\'email de l\'entreprise est obligatoire.',
            'email.email' => 'L\'email de l\'entreprise est invalide.',
            'email.unique' => 'Cet email est déjà utilisé par une autre entreprise.',
            'phone_one.required' => 'Le téléphone principal est obligatoire.',
            'primary_color.regex' => 'La couleur principale doit être au format #RRGGBB.',
            'secondary_color.regex' => 'La couleur secondaire doit être au format #RRGGBB.',
            'logo.image' => 'Le logo doit être une image.',
            'logo.mimes' => 'Le logo doit être au format JPG, PNG ou WEBP.',
            'logo.max' => 'Le logo ne doit pas dépasser 2 Mo.',
        ]);

        $company->update([
            'name' => $validated['name'],
            'short_name' => $validated['short_name'],
            'slogan' => $validated['slogan'] ?? null,
            'head_office_address' => $validated['head_office_address'],
            'email' => $validated['email'],
            'phone_one' => $validated['phone_one'],
            'phone_two' => $validated['phone_two'] ?? null,
            'primary_color' => $validated['primary_color'],
            'secondary_color' => $validated['secondary_color'],
        ]);

        if ($request->hasFile('logo')) {
            $fileValidator->validateAndStoreFile($request->file('logo'), $company, 'logo');
        } elseif ($request->boolean('remove_logo')) {
            $company->clearMediaCollection('logo');
        }

        return response()->json([
            'success' => true,
            'message' => 'Les informations de l\'entreprise ont été mises à jour.',
            'data' => $this->payload($company->fresh()),
        ]);
    }

    private function payload($company): array
    {
        $company->loadCount('stores');

        return [
            'id' => $company->id,
            'name' => $company->name,
            'short_name' => $company->short_name,
            'slogan' => $company->slogan,
            'head_office_address' => $company->head_office_address,
            'email' => $company->email,
            'phone_one' => $company->phone_one,
            'phone_two' => $company->phone_two,
            'primary_color' => $company->primary_color,
            'secondary_color' => $company->secondary_color,
            'logo_url' => $company->logo_url,
            'stores_count' => $company->stores_count,
        ];
    }
}
