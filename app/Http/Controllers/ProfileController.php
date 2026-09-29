<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthCookieService;
use App\Services\JwtService;
use App\Services\SecurityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Profil de l'utilisateur connecté : toutes les actions portent sur le compte authentifié,
 * jamais sur un identifiant transmis par le client.
 */
class ProfileController extends Controller
{
    public function __construct(
        protected JwtService $jwtService,
        protected AuthCookieService $cookieService,
        protected SecurityLogger $journal,
    ) {
    }

    public function show(Request $request)
    {
        return response()->json($this->profilePayload($request->user()));
    }

    public function updateContacts(Request $request)
    {
        $validated = $request->validate([
            'phone_number_one' => 'required|string|max:20',
            'phone_number_two' => 'nullable|string|max:20|different:phone_number_one',
            'address' => 'required|string|max:255',
        ], [
            'phone_number_one.required' => 'Le téléphone principal est obligatoire.',
            'phone_number_one.max' => 'Le téléphone principal ne doit pas dépasser 20 caractères.',
            'phone_number_two.max' => 'Le téléphone secondaire ne doit pas dépasser 20 caractères.',
            'phone_number_two.different' => 'Le téléphone secondaire doit être différent du principal.',
            'address.required' => 'L\'adresse est obligatoire.',
            'address.max' => 'L\'adresse ne doit pas dépasser 255 caractères.',
        ]);

        $user = $request->user();
        $user->update([
            'phone_number_one' => $validated['phone_number_one'],
            'phone_number_two' => $validated['phone_number_two'] ?? null,
            'address' => $validated['address'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Vos coordonnées ont été mises à jour.',
            'data' => $this->profilePayload($user->fresh()),
        ]);
    }

    public function updatePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required|string',
            'new_password' => [
                'required',
                'string',
                'confirmed',
                'different:current_password',
                'min:8',
                // Au moins une lettre et un chiffre
                'regex:/[A-Za-z]/',
                'regex:/[0-9]/',
            ],
        ], [
            'current_password.required' => 'Le mot de passe actuel est obligatoire.',
            'new_password.required' => 'Le nouveau mot de passe est obligatoire.',
            'new_password.confirmed' => 'La confirmation du nouveau mot de passe ne correspond pas.',
            'new_password.different' => 'Le nouveau mot de passe doit être différent de l\'actuel.',
            'new_password.min' => 'Le nouveau mot de passe doit contenir au moins 8 caractères.',
            'new_password.regex' => 'Le nouveau mot de passe doit contenir au moins une lettre et un chiffre.',
        ]);

        $user = $request->user();

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Le mot de passe actuel est incorrect.',
                'errors' => ['current_password' => ['Le mot de passe actuel est incorrect.']],
            ], 422);
        }

        $user->password = Hash::make($validated['new_password']);
        $user->must_change_password = false;
        $user->save();

        // Toutes les sessions du compte (tous appareils) sont fermées
        $this->jwtService->revokeAllForUser($user->id);
        $this->journal->log(SecurityLogger::PASSWORD_CHANGED, $user->id);

        return response()->json([
            'success' => true,
            'message' => 'Mot de passe modifié. Reconnectez-vous avec votre nouveau mot de passe.',
        ])->withCookie($this->cookieService->forget());
    }

    private function profilePayload(User $user): array
    {
        $user->loadMissing(['role', 'media', 'owner.company.stores', 'manager.store.company', 'seller.store.company']);

        $store = $user->manager?->store ?? $user->seller?->store;
        $company = $user->owner?->company ?? $store?->company;

        return [
            'user' => new UserResource($user),
            'company' => $company ? [
                'id' => $company->id,
                'name' => $company->name,
                'logo_url' => $company->logo_url,
            ] : null,
            'store' => $store ? ['id' => $store->id, 'name' => $store->name] : null,
            'stores_count' => $user->owner?->company?->stores->count(),
            'member_since' => $user->created_at?->toDateString(),
        ];
    }
}
