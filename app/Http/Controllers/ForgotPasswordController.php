<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

class ForgotPasswordController extends Controller
{

    public function sendResetLinkEmail(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
            ]);

            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return response()->json([
                    'message' => 'Aucun utilisateur trouvé avec cette adresse email.',
                    'status' => 'email_not_found'
                ], 404);
            }

            // Log pour debugging
            Log::info('Tentative de réinitialisation pour: ' . $request->email);

            // Envoi du lien de réinitialisation
            $status = Password::broker('users')->sendResetLink(
                $request->only('email')
            );

            if ($status === Password::RESET_LINK_SENT) {
                return response()->json([
                    'message' => 'Lien de réinitialisation du mot de passe envoyé à votre adresse électronique.',
                    'status' => $status
                ]);
            }

            Log::error('Erreur reset password: ' . $status);

            $message = match ($status) {
                Password::RESET_THROTTLED => 'Veuillez attendre avant de réessayer.',
                default => 'Erreur lors de l\'envoi du lien. Veuillez réessayer.'
            };

            return response()->json(['message' => $message], 400);

        } catch (\Exception $e) {
            Log::error('Exception lors du reset password: ' . $e->getMessage());
            return response()->json([
                'message' => 'Une erreur est survenue.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

//     public function sendResetLinkEmail(Request $request)
// {
//     try {
//         $request->validate([
//             'email' => 'required|email|exists:users,email',
//         ]);

//         // Log pour debugging
//         Log::info('Tentative de réinitialisation pour: ' . $request->email);

//         // Utilisez le broker standard, qui utilise maintenant la notification personnalisée
//         // grâce à la méthode sendPasswordResetNotification du modèle User
//         $status = Password::broker('users')->sendResetLink(
//             $request->only('email')
//         );

//         if ($status === Password::RESET_LINK_SENT) {
//             return response()->json([
//                 'message' => 'Lien de réinitialisation du mot de passe envoyé à votre adresse électronique',
//                 'status' => $status
//             ]);
//         }

//         // Log l'erreur spécifique
//         Log::error('Erreur reset password: ' . $status);

//         // Retourner un message plus spécifique
//         $message = match ($status) {
//             Password::INVALID_USER => 'Email non trouvé dans notre base de données.',
//             Password::RESET_THROTTLED => 'Veuillez attendre avant de réessayer.',
//             default => 'Erreur lors de l\'envoi du lien. Veuillez réessayer.'
//         };

//         return response()->json(['message' => $message], 400);

//     } catch (\Exception $e) {
//         Log::info($e);
//         Log::error('Exception lors du reset password: ' . $e->getMessage());
//         return response()->json([
//             'message' => 'Une erreur est survenue',
//             'error' => $e->getMessage()
//         ], 500);
//     }
// }
}
