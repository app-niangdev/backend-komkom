<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;

class EmailVerifyController extends Controller
{
    /**
     * Vérifie l'email d'un utilisateur.
     */
    public function verify(Request $request, $id, $hash)
    {
        $user = User::findOrFail($id);

        // Vérifier si l'URL n'a pas été manipulée
        if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            return response()->json([
                'success' => false,
                'message' => 'Le lien de vérification est invalide ou a été modifié.'
            ], 403);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => true,
                'message' => 'Votre adresse email a déjà été vérifiée.'
            ]);
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response()->json([
            'success' => true,
            'message' => 'Votre adresse email a été vérifiée avec succès.'
        ]);
    }

    /**
     * Renvoie l'email de vérification.
     */
    public function resend(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur introuvable.'
            ], 404);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => true,
                'message' => 'Votre adresse email est déjà vérifiée.'
            ]);
        }

        // Envoi de l'email de vérification
        \App\Jobs\SendVerificationEmail::dispatch($user);

        return response()->json([
            'success' => true,
            'message' => 'Un nouvel email de vérification vous a été envoyé.'
        ]);
    }
}
