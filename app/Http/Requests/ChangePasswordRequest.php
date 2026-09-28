<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
class ChangePasswordRequest extends FormRequest
{
    // Autorise la requête
    public function authorize()
    {
        return true; // Changez ceci selon votre logique d'autorisation
    }

    // Définit les règles de validation
    public function rules(): array
    {
        return [
            'current_password' => 'required|string|min:8',
            'new_password' => 'required|string|min:8|confirmed',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json(
            [
                'success' => false,
                'error' => true,
                'message' => 'Erreur de validation',
                'ErrorList' => $validator->errors()
            ],
            422
        ));
    }

    public function messages()
    {
        return [
            'current_password.required' => 'Le mot de passe actuel est obligatoire.',
            'current_password.string' => 'Le mot de passe actuel doit être une chaîne de caractères.',
            'current_password.min' => 'Le mot de passe actuel doit contenir au moins 8 caractères.',
            'new_password.required' => 'Le nouveau mot de passe est obligatoire.',
            'new_password.string' => 'Le nouveau mot de passe doit être une chaîne de caractères.',
            'new_password.min' => 'Le nouveau mot de passe doit contenir au moins 8 caractères.',
            'new_password.confirmed' => 'La confirmation du nouveau mot de passe ne correspond pas.',
        ];
    }
}
