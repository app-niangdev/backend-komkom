<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

class ForgotPasswordController extends Controller
{
    /**
     * Envoie le lien de réinitialisation.
     * La réponse est la même que le compte existe ou non : on ne révèle pas quelles adresses sont inscrites.
     */
    public function sendResetLinkEmail(Request $request)
    {
        $request->validate(
            ['email' => 'required|email'],
            ['email.required' => 'L\'adresse e-mail est obligatoire.', 'email.email' => 'L\'adresse e-mail n\'est pas valide.']
        );

        try {
            $status = Password::broker('users')->sendResetLink($request->only('email'));
            if ($status !== Password::RESET_LINK_SENT) {
                Log::info('Réinitialisation de mot de passe non envoyée', ['status' => $status]);
            }
        } catch (\Throwable $e) {
            // Serveur SMTP indisponible, etc. : l'utilisateur peut réessayer
            Log::error('Envoi du lien de réinitialisation impossible : ' . $e->getMessage());

            return response()->json([
                'message' => 'L\'e-mail n\'a pas pu être envoyé. Veuillez réessayer dans quelques minutes.',
            ], 503);
        }

        return response()->json([
            'message' => 'Si un compte existe pour cette adresse, un lien de réinitialisation vient d\'y être envoyé.',
        ]);
    }
}
