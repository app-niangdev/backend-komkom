<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerRequest extends FormRequest
{
    /**
     * Vérifie si l'utilisateur est autorisé à effectuer cette requête.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Règles de validation applicables à la requête.
     */
    public function rules(): array
    {
        $supplierId = $this->route('id'); // utilisé pour ignorer l'unicité lors d'une mise à jour

        return [
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:15'],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('supplierproducts', 'email')->ignore($supplierId),
            ],
            'store_id' => ['required', 'integer', 'exists:stores,id'],
        ];
    }

    /**
     * Messages d’erreur personnalisés.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Le nom du fournisseur est requis.',
            'name.string' => 'Le nom doit être une chaîne de caractères.',
            'name.max' => 'Le nom ne doit pas dépasser 255 caractères.',

            'address.required' => "L'adresse est requise.",
            'address.string' => "L'adresse doit être une chaîne de caractères.",
            'address.max' => "L'adresse ne doit pas dépasser 255 caractères.",

            'phone.required' => 'Le premier numéro de téléphone est requis.',
            'phone.string' => 'Le premier numéro de téléphone doit être une chaîne de caractères.',
            'phone.max' => 'Le premier numéro de téléphone ne doit pas dépasser 15 caractères.',

            'email.email' => "L'adresse e-mail doit être valide.",
            'email.max' => "L'adresse e-mail ne doit pas dépasser 255 caractères.",
            'email.unique' => "Cette adresse e-mail est déjà utilisée.",

            'store_id.required' => 'Le magasin est requis.',
            'store_id.integer' => 'Le magasin doit être un entier valide.',
            'store_id.exists' => 'Le magasin sélectionné est invalide.',
        ];
    }
}
