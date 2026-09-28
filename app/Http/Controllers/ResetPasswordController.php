<?php

namespace App\Http\Controllers;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class ResetPasswordController extends Controller
{
    public function reset(Request $request)
    {
        Log::info('Données brutes reçues', [
            'all' => $request->all(),
            'token' => $request->token,
            'email' => $request->email
        ]);

        try {
            $request->validate([
                'token' => 'required',
                'email' => 'required|email',
                'password' => 'required|confirmed|min:8',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Erreur de validation', [
                'errors' => $e->errors()
            ]);
            throw $e;
        }

        Log::info('Validation réussie, tentative de réinitialisation');

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                Log::info('Utilisateur trouvé, mise à jour du mot de passe', [
                    'user_id' => $user->id,
                    'email' => $user->email
                ]);

                try {
                    $user->forceFill([
                        'password' => Hash::make($password),
                        'remember_token' => Str::random(60),
                    ])->save();

                    event(new PasswordReset($user));
                    Log::info('Mot de passe mis à jour avec succès');
                } catch (\Exception $e) {
                    Log::error('Erreur lors de la mise à jour du mot de passe', [
                        'error' => $e->getMessage()
                    ]);
                    throw $e;
                }
            }
        );

        Log::info('Statut de la réinitialisation', ['status' => $status]);

        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => 'Réinitialisation du mot de passe réussie'])
            : response()->json(['message' => 'Impossible de réinitialiser le mot de passe'], 400);
    }
}
