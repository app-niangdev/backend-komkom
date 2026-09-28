<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangePasswordRequest;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class ChangePasswordController extends Controller
{
    public function changePassword(ChangePasswordRequest $request, $id)
    {
        $validated = $request->validated();
        $user = User::findOrFail($id);

        // Seul le compte connecté peut changer son mot de passe (préférer PUT /profile/password)
        if ($user->id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous ne pouvez modifier que votre propre mot de passe.',
            ], 403);
        }

        // Vérifie si le mot de passe actuel est correct
        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'success' => false,
                'error' => true,
                'message' => 'Le mot de passe actuel est incorrect.',
            ], 422);
        }

        // Met à jour le mot de passe
        $user->password = Hash::make($validated['new_password']);
        $user->save();

        app('App\Http\Controllers\AuthController')->logout($request);
        return response()->json([
            'success' => true,
            'message' => 'Mot de passe changé avec succès. Veuillez vous reconnecter',
        ], 200);
    }

}
