<?php

namespace App\Http\Controllers;

use App\Http\Resources\StoreResource;
use App\Models\Store;
use App\Services\FileValidationService;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class StoreController extends Controller
{
    protected FileValidationService $fileValidator;

    public function __construct(FileValidationService $fileValidator)
    {
        $this->fileValidator = $fileValidator;
    }

    /**
     * Liste paginée des stores avec recherche
     */
    public function index(Request $request, $id)
    {
        try {
            $perPage = $request->input('perPage', 10);
            $page = $request->input('page', 1);
            $search = $request->input('search', '');
            $searchStatus = $request->input('searchStatus');

            // ⚙️ Base query : uniquement les stores de la société donnée
            $query = Store::where('company_id', $id);

            // 🔍 Recherche globale
            if (!empty($search)) {
                $query->where(function ($builder) use ($search) {
                    $builder->where('name', 'ILIKE', "%{$search}%")
                            ->orWhere('email', 'ILIKE', "%{$search}%")
                            ->orWhere('phone_one', 'ILIKE', "%{$search}%")
                            ->orWhere('phone_two', 'ILIKE', "%{$search}%")
                            ->orWhere('phone_three', 'ILIKE', "%{$search}%")
                            ->orWhere('address', 'ILIKE', "%{$search}%");
                });
            }

            // ✅ Filtrage par statut (active/inactive)
            if ($searchStatus !== null && $searchStatus !== '') {
                $status = filter_var($searchStatus, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $query->where('active', $status ?? $searchStatus);
            }

            // 📄 Pagination + tri
            $stores = $query->orderByDesc('created_at')
                            ->paginate($perPage, ['*'], 'page', $page);

            // 🔁 Réponse uniforme
            return response()->json([
                'status' => true,
                'message' => 'Liste des Boutiques récupérée avec succès.',
                'data' => StoreResource::collection($stores),
                'meta' => [
                    'current_page' => $stores->currentPage(),
                    'per_page' => $stores->perPage(),
                    'total' => $stores->total(),
                    'last_page' => $stores->lastPage(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur dans StoreController@index : ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la récupération des Boutiques.',
            ], 500);
        }
    }

    /**
     * Créer un store
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'name' => 'required|string|max:255',
                'slogan' => 'nullable|string|max:255',
                'address' => 'required|string',
                'phone_one' => 'required|string',
                'phone_two' => 'nullable|string',
                'phone_three' => 'nullable|string',
                'email' => 'nullable|email',
                'uses_measurements' => 'sometimes|boolean',
                'uses_serial_numbers' => 'sometimes|boolean',
                'ticket_width' => 'sometimes|in:58,80',
                'whatsapp_invoices_enabled' => 'sometimes|boolean',
                'use_company_logo' => 'sometimes|boolean',
                'use_company_colors' => 'sometimes|boolean',
                'primary_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
                'secondary_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
                'logo' => 'sometimes|image|mimes:jpeg,png,jpg,gif,webp,svg|max:2048',
            ], [
                'company_id.required' => 'L\'identifiant de la société est obligatoire',
                'company_id.exists' => 'La société sélectionnée est invalide',
                'name.required' => 'Le nom est obligatoire',
                'name.max' => 'Le nom ne doit pas dépasser 255 caractères',
                'slogan.max' => 'Le slogan ne doit pas dépasser 255 caractères',
                'address.required' => 'L\'adresse est obligatoire',
                'phone_one.required' => 'Le numéro de téléphone principal est obligatoire',
                'email.email' => 'L\'email n\'est pas valide',
                'primary_color.regex' => 'La couleur primaire doit être un code hexadécimal valide (#RRGGBB)',
                'secondary_color.regex' => 'La couleur secondaire doit être un code hexadécimal valide (#RRGGBB)',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            $validatedData = $validator->validated();
            unset($validatedData['logo']);
            $validatedData['active'] = true;
            $validatedData['uses_measurements'] = $validatedData['uses_measurements'] ?? true;
            $validatedData['uses_serial_numbers'] = $validatedData['uses_serial_numbers'] ?? true;
            $validatedData['use_company_logo'] = $validatedData['use_company_logo'] ?? true;
            $validatedData['use_company_colors'] = $validatedData['use_company_colors'] ?? true;
            // Hors $fillable : réservé à l'administrateur
            $whatsapp = $this->isAdmin($request) && (bool) ($validatedData['whatsapp_invoices_enabled'] ?? false);
            unset($validatedData['whatsapp_invoices_enabled']);
            $store = Store::create($validatedData);
            $store->forceFill(['whatsapp_invoices_enabled' => $whatsapp])->save();
            app(SubscriptionService::class)->createTrial($store, $request->user()?->id);

            // Validation et ajout du logo propre au store, si présent
            if ($request->hasFile('logo')) {
                $this->fileValidator->validateAndStoreFile($request->file('logo'), $store, 'logo');
            }

            return response()->json([
                'status' => true,
                'message' => 'Boutique créé avec succès',
            ], 201);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la création du store: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la création du Boutique'
            ], 500);
        }
    }

    /**
     * Modifier un store
     */
    public function update(Request $request, $id)
    {
        try {
            $store = Store::findOrFail($id);

            $rules = [
                'company_id' => 'required|exists:companies,id',
                'name' => 'required|string|max:255',
                'slogan' => 'nullable|string|max:255',
                'address' => 'required|string',
                'phone_one' => 'required|string',
                'phone_two' => 'nullable|string',
                'phone_three' => 'nullable|string',
                'email' => 'nullable|email',
                'uses_measurements' => 'sometimes|boolean',
                'uses_serial_numbers' => 'sometimes|boolean',
                'ticket_width' => 'sometimes|in:58,80',
                'whatsapp_invoices_enabled' => 'sometimes|boolean',
                'use_company_logo' => 'sometimes|boolean',
                'use_company_colors' => 'sometimes|boolean',
                'primary_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
                'secondary_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
                'logo' => 'sometimes|image|mimes:jpeg,png,jpg,gif,webp,svg|max:2048',
            ];

            $messages = [
                'company_id.required' => 'L\'identifiant de la société est obligatoire',
                'company_id.exists' => 'La société sélectionnée est invalide',
                'name.required' => 'Le nom est obligatoire',
                'name.max' => 'Le nom ne doit pas dépasser 255 caractères',
                'slogan.max' => 'Le slogan ne doit pas dépasser 255 caractères',
                'address.required' => 'L\'adresse est obligatoire',
                'phone_one.required' => 'Le numéro de téléphone principal est obligatoire',
                'email.email' => 'L\'email n\'est pas valide',
                'primary_color.regex' => 'La couleur primaire doit être un code hexadécimal valide (#RRGGBB)',
                'secondary_color.regex' => 'La couleur secondaire doit être un code hexadécimal valide (#RRGGBB)',
            ];

            $validator = Validator::make($request->all(), $rules, $messages);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            $validatedData = $validator->validated();
            unset($validatedData['logo']);

            if (!$request->boolean('uses_serial_numbers', true) && $store->uses_serial_numbers && $store->hasSerialProducts()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Erreur de validation',
                    'errors' => ['uses_serial_numbers' => [Store::SERIALS_IN_USE_MESSAGE]],
                ], 422);
            }

            if (array_key_exists('whatsapp_invoices_enabled', $validatedData) && $this->isAdmin($request)) {
                $store->forceFill(['whatsapp_invoices_enabled' => (bool) $validatedData['whatsapp_invoices_enabled']]);
            }
            unset($validatedData['whatsapp_invoices_enabled']);
            $store->update($validatedData);

            // Validation et mise à jour du logo propre au store
            if ($request->hasFile('logo')) {
                $this->fileValidator->validateAndStoreFile($request->file('logo'), $store, 'logo');
            }

            return response()->json([
                'status' => true,
                'message' => 'Boutique modifié avec succès',
                'data' => $store
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Boutique non trouvé'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la modification du store: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la modification du Boutique'
            ], 500);
        }
    }

    /**
     * Supprimer un store
     */
    public function destroy($id)
    {
        try {
            $store = Store::find($id);

            if (!$store) {
                return response()->json([
                    'status' => false,
                    'message' => 'Boutique non trouvé'
                ], 404);
            }

            // Vérifier si la boutique a des produits
            if (\App\Models\Product::where('store_id', $id)->exists()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Impossible de supprimer cette boutique car elle contient des produits.'
                ], 422);
            }

            // Vérifier si la boutique a des catégories
            if (\App\Models\Category::where('store_id', $id)->exists()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Impossible de supprimer cette boutique car elle contient des catégories.'
                ], 422);
            }

            // Vérifier si la boutique a des ventes
            if (\App\Models\Sale::where('store_id', $id)->exists()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Impossible de supprimer cette boutique car elle a des ventes enregistrées.'
                ], 422);
            }

            // Vérifier si la boutique a des approvisionnements
            if (\App\Models\Supply::where('store_id', $id)->exists()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Impossible de supprimer cette boutique car elle a des approvisionnements enregistrés.'
                ], 422);
            }

            // Vérifier si la boutique a des utilisateurs (sellers ou managers)
            if (\App\Models\Seller::where('store_id', $id)->exists() ||
                \App\Models\Manager::where('store_id', $id)->exists()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Impossible de supprimer cette boutique car elle a des utilisateurs associés (vendeurs ou managers).'
                ], 422);
            }

            // Vérifier si la boutique a des clients
            if (\App\Models\Customer::where('store_id', $id)->exists()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Impossible de supprimer cette boutique car elle a des clients enregistrés.'
                ], 422);
            }

            // Vérifier si la boutique a des fournisseurs
            if (\App\Models\Supplierproduct::where('store_id', $id)->exists()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Impossible de supprimer cette boutique car elle a des fournisseurs enregistrés.'
                ], 422);
            }

            $store->delete();

            return response()->json([
                'status' => true,
                'message' => 'Boutique supprimé avec succès'
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la suppression du store: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la suppression du Boutique'
            ], 500);
        }
    }

    public function changeStatus($id)
    {
        $store = Store::findOrFail($id);
        $store->active = !$store->active;
        $store->save();
        return response()->json([
            'success' => true,
            'message' => $store->active ? 'Boutique activée avec succès.' : 'Boutique désactivée avec succès.',
        ]);
    }

    /**
     * Récupère les infos publiques d'une boutique avec le logo de sa société.
     * Route publique : ne nécessite pas d'authentification.
     */
    public function getStoreInfo($id)
    {
        try {
            $store = Store::with('company')->find($id);

            if (!$store) {
                return response()->json([
                    'status' => false,
                    'message' => 'Boutique non trouvée'
                ], 404);
            }

            return response()->json([
                'status' => true,
                'data' => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'slogan' => $store->slogan,
                    'address' => $store->address,
                    'phone_one' => $store->phone_one,
                    'phone_two' => $store->phone_two,
                    'phone_three' => $store->phone_three,
                    'email' => $store->email,
                    'active' => $store->active,
                    'logo_url' => $store->logo_url,
                    'primary_color' => $store->effective_primary_color,
                    'secondary_color' => $store->effective_secondary_color,
                    'company' => [
                        'id' => $store->company->id,
                        'name' => $store->company->name,
                        'logo_url' => $store->company->logo_url,
                        'primary_color' => $store->company->primary_color,
                        'secondary_color' => $store->company->secondary_color,
                    ],
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur dans StoreController@getStoreInfo : ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la récupération de la boutique.'
            ], 500);
        }
    }

    /** Envoi WhatsApp des factures : seul l'administrateur l'active ou le désactive. */
    private function isAdmin(Request $request): bool
    {
        return $request->user()?->role?->name === 'Admin';
    }
}
