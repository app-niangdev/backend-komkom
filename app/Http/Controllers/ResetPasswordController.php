<?php

namespace App\Http\Controllers;

use App\Services\JwtService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class ResetPasswordController extends Controller
{
    public function __construct(protected JwtService $jwtService)
    {
    }

    public function reset(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|confirmed|min:8',
        ], [
            'token.required' => 'Lien de réinitialisation incomplet : redemandez un lien.',
            'email.required' => 'Lien de réinitialisation incomplet : redemandez un lien.',
            'email.email' => 'Lien de réinitialisation invalide : redemandez un lien.',
            'password.required' => 'Le nouveau mot de passe est obligatoire.',
            'password.confirmed' => 'Les deux mots de passe ne correspondent pas.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                    'must_change_password' => false,
                ])->save();

                // Mot de passe changé : les sessions ouvertes ailleurs sont fermées
                $this->jwtService->revokeAllForUser($user->id);

                event(new PasswordReset($user));
            }
        );

        return match ($status) {
            Password::PASSWORD_RESET => response()->json(['message' => 'Mot de passe réinitialisé : vous pouvez vous connecter.']),
            Password::INVALID_TOKEN => response()->json(['message' => 'Ce lien a expiré ou a déjà été utilisé : redemandez un lien de réinitialisation.'], 400),
            default => response()->json(['message' => 'Impossible de réinitialiser le mot de passe : redemandez un lien.'], 400),
        };
    }
}
