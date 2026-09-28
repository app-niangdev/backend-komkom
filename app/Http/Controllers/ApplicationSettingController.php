<?php

namespace App\Http\Controllers;

use App\Models\ApplicationSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ApplicationSettingController extends Controller
{
    public function index()
    {
        $settings = ApplicationSetting::first();

        if (!$settings) {
            return response()->json(["message" => "Aucun paramètre d'application trouvé"], 404);
        }
        if ($settings->logo) {
            $settings->logo_url = url('storage/' . $settings->logo);
        } else {
            $settings->logo_url = null;
        }
        return response()->json([
            'data'=> $settings
        ]);
    }

    public function update(Request $request, $id)
    {
        $settings = ApplicationSetting::findOrFail($id);

        if ($request->hasFile('logo')) {
            if ($settings->logo) {
                Storage::disk('public')->delete($settings->logo);
            }
            $settings->logo = $request->file('logo')->store('images', 'public');
        }

        // Mise à jour des autres champs
        $settings->fill($request->except('logo'));

        $settings->save();

        return response()->json([
            'message' => 'Informations mises à jour avec succès'
        ], 200, [], JSON_UNESCAPED_SLASHES);
    }
}
