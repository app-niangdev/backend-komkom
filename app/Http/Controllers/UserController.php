<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Owner;
use App\Models\Manager;
use App\Models\Role;
use App\Models\Seller;
use App\Models\Store;
use App\Services\FileValidationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{

    public function index(Request $request)
    {
        $user = $request->user();

        $query = User::query()
            ->with([
                'role',
                'company',
                'seller.store.company',
                'manager.store.company'
            ])
            ->orderBy('created_at', 'desc');

        // 🔍 Recherche
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                ->orWhere('last_name', 'ilike', "%{$search}%")
                ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        // 🎯 Filtrage selon le rôle
        if ($user->role->name === 'Admin') {
            // L'admin voit tous les utilisateurs, avec filtres optionnels
            if ($roleId = $request->input('role_id')) {
                $query->where('role_id', $roleId);
            }

            $status = $request->input('status');
            if ($status !== null && $status !== '') {
                $query->where('status', filter_var($status, FILTER_VALIDATE_BOOLEAN));
            }

            if ($companyId = $request->input('company_id')) {
                $query->where(function ($q) use ($companyId) {
                    $q->where('users.company_id', $companyId)
                        ->orWhereHas('owner.company', fn ($c) => $c->where('companies.id', $companyId))
                        ->orWhereHas('manager.store', fn ($st) => $st->where('company_id', $companyId))
                        ->orWhereHas('seller.store', fn ($st) => $st->where('company_id', $companyId));
                });
            }
        } elseif ($user->role->name === 'Owner') {

            $company = $user->owner?->company;

            if ($company) {
                $storeIds = $company->stores()->pluck('id');

                $query->where(function ($q) use ($storeIds, $user) {

                    // Sellers des stores de la company
                    $q->whereHas('seller', function ($subQ) use ($storeIds) {
                        $subQ->whereIn('store_id', $storeIds);
                    })

                    // Managers des stores de la company (1 store par manager)
                    ->orWhereHas('manager', function ($subQ) use ($storeIds) {
                        $subQ->whereIn('store_id', $storeIds);
                    })

                    // Inclure l'owner connecté
                    ->orWhere('users.id', $user->id);
                });
            } else {
                $query->whereRaw('1=0');
            }

        } elseif ($user->role->name === 'Manager') {

            $manager = $user->manager;

            if ($manager && $manager->store_id) {
                $storeId = $manager->store_id;

                $query->where(function ($q) use ($storeId) {

                    // Sellers du même store
                    $q->whereHas('seller', function ($subQ) use ($storeId) {
                        $subQ->where('store_id', $storeId);
                    })

                    // Managers du même store
                    ->orWhereHas('manager', function ($subQ) use ($storeId) {
                        $subQ->where('store_id', $storeId);
                    });
                });
            } else {
                $query->whereRaw('1=0');
            }

        } else {
            // Autres rôles
            $query->whereRaw('1=0');
        }

        // 📄 Pagination
        $perPage = $request->input('per_page', 10);
        $users = $query->paginate($perPage);

        return response()->json([
            'data' => $users->items(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'per_page'     => $users->perPage(),
                'total'        => $users->total(),
                'last_page'    => $users->lastPage(),
            ],
        ], 200);
    }

    public function indexOwners(Request $request)
    {
        $query = User::whereHas('owner')
            ->with(['role', 'owner'])
            ->orderBy('created_at', 'desc');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhereHas('owner', function ($ownerQuery) use ($search) {
                        $ownerQuery->where('phone_number_one', 'ilike', "%{$search}%")
                            ->orWhere('phone_number_two', 'ilike', "%{$search}%")
                            ->orWhere('address', 'ilike', "%{$search}%");
                    });
            });
        }

        // Pagination (10 par défaut)
        $perPage = $request->input('per_page', 10);
        $owners = $query->paginate($perPage);

        return response()->json($owners);
    }

    public function store(Request $request)
    {
        $role = Role::find($request->role_id);
        $rules = [
            'first_name'         => 'required|string|max:100',
            'last_name'          => 'required|string|max:100',
            'email'              => 'required|email|unique:users,email',
            'role_id'            => 'required|exists:roles,id',
            'phone_number_one'   => 'required|string|max:20',
            'phone_number_two'   => 'nullable|string|max:20',
            'address'            => 'nullable|string|max:255',
            'gender'             => 'nullable|in:male,female',
            'active'             => 'nullable|boolean',
            'store_id'           => 'nullable|exists:stores,id',
        ];

        if ($role && in_array(strtolower($role->name), ['seller', 'manager'])) {
            $rules['store_id'] = 'required|exists:stores,id';
        }

        // 🔹 Validation
        $validator = Validator::make($request->all(), $rules, [
            'first_name.required' => 'Le prénom est obligatoire.',
            'last_name.required'  => 'Le nom est obligatoire.',
            'email.required'      => 'L\'adresse e-mail est obligatoire.',
            'email.email'         => 'L\'adresse e-mail doit être valide.',
            'email.unique'        => 'Cette adresse e-mail est déjà utilisée.',
            'role_id.exists'      => 'Le rôle sélectionné est invalide.',
            'store_id.required'   => 'La boutique est obligatoire pour les gestionnaires et les vendeurs.',
            'store_id.exists'     => 'La boutique sélectionnée est invalide.',
        ]);

        if ($validator->fails()) {
            Log::warning('Validation échouée', ['errors' => $validator->errors()]);
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        // 🔹 Données validées
        $validated = $validator->validated();
        try {
            DB::beginTransaction();

            // 🔹 Création de l’utilisateur principal
            $user = User::create([
                'first_name' => $validated['first_name'],
                'last_name'  => $validated['last_name'],
                'email'      => $validated['email'],
                'password'   => Hash::make('password'),
                'role_id'    => $validated['role_id'],
                'type'       => strtolower($role->name),
                'phone_number_one' => $validated['phone_number_one'],
                'phone_number_two' => $validated['phone_number_two'] ?? null,
                'address'          => $validated['address'] ?? 'adresse',
                'gender'           => $validated['gender'] ?? 'male',
                'email_verified_at' => Carbon::now()
            ]);

            if (in_array(strtolower($role->name), ['manager', 'seller'])) {
                $store = Store::find($validated['store_id']);
                $user->update(['store_id' => $store->id, 'company_id' => $store->company_id]);
            }

            // 🔹 Création du profil spécifique selon le rôle
            switch (strtolower($role->name)) {
                case 'owner':
                    Owner::create([
                        'user_id'=> $user->id,
                    ]);
                    break;

                case 'manager':
                    Manager::create([
                        'user_id'=> $user->id,
                        'store_id'         => $validated['store_id'],
                    ]);
                    break;

                case 'seller':
                    Seller::create([
                        'user_id'          => $user->id,
                        'store_id'         => $validated['store_id'],
                    ]);
                    break;
            }

            DB::commit();

            Log::info('Utilisateur créé avec succès', ['id' => $user->id]);

            return response()->json([
                'success' => true,
                'message' => 'Utilisateur créé avec succès.',
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Erreur lors de la création de l’utilisateur', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la création de l’utilisateur.',
            ], 500);
        }
    }

    public function update(Request $request, $id, FileValidationService $fileValidator)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non trouvé.',
            ], 404);
        }

        $role = Role::find($request->role_id);
        $rules = [
            'first_name'         => 'required|string|max:100',
            'last_name'          => 'required|string|max:100',
            'email'              => 'required|email|unique:users,email,' . $id,
            'role_id'            => 'required|exists:roles,id',
            'phone_number_one'   => 'required|string|max:20',
            'phone_number_two'   => 'nullable|string|max:20',
            'address'            => 'nullable|string|max:255',
            'gender'             => 'nullable|in:male,female',
            'active'             => 'nullable|boolean',
            'store_id'           => 'nullable|exists:stores,id',
        ];

        if ($role && in_array(strtolower($role->name), ['seller', 'manager'])) {
            $rules['store_id'] = 'required|exists:stores,id';
        }

        // 🔹 Validation
        $validator = Validator::make($request->all(), $rules, [
            'first_name.required' => 'Le prénom est obligatoire.',
            'last_name.required'  => 'Le nom est obligatoire.',
            'email.required'      => 'L\'adresse e-mail est obligatoire.',
            'email.email'         => 'L\'adresse e-mail doit être valide.',
            'email.unique'        => 'Cette adresse e-mail est déjà utilisée.',
            'role_id.exists'      => 'Le rôle sélectionné est invalide.',
            'store_id.required'   => 'La boutique est obligatoire pour les gestionnaires et les vendeurs.',
            'store_id.exists'     => 'La boutique sélectionnée est invalide.',
        ]);

        if ($validator->fails()) {
            Log::warning('Validation échouée lors de la mise à jour', ['errors' => $validator->errors()]);
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        // 🔹 Données validées
        $validated = $validator->validated();

        // Le profil lié (owner / manager / seller) dépend du rôle : on n'autorise pas de le changer ici
        if ((int) $validated['role_id'] !== (int) $user->role_id) {
            return response()->json([
                'success' => false,
                'message' => 'Le changement de rôle d\'un utilisateur existant n\'est pas pris en charge.',
            ], 422);
        }

        try {
            DB::beginTransaction();

            // 🔹 Changement de boutique d'un manager / vendeur
            if (in_array(strtolower($role->name), ['manager', 'seller'])) {
                $store = Store::find($validated['store_id']);
                $profile = strtolower($role->name) === 'manager' ? $user->manager : $user->seller;
                $profile?->update(['store_id' => $store->id]);
                $user->store_id = $store->id;
                $user->company_id = $store->company_id;
            }

            if ($request->hasFile('image')) {
                $file = $request->file('image');
                $fileValidator->validateAndStoreFile($file, $user, 'image');
            }

            // 🔹 Mise à jour de l'utilisateur principal
            $user->update([
                'first_name' => $validated['first_name'],
                'last_name'  => $validated['last_name'],
                'email'      => $validated['email'],
                'role_id'    => $validated['role_id'],
                'type'       => strtolower($role->name),
                'phone_number_one' => $validated['phone_number_one'],
                'phone_number_two' => $validated['phone_number_two'] ?? null,
                'address'          => $validated['address'] ?? $user->address,
                'gender'           => $validated['gender'] ?? $user->gender,
            ]);

            DB::commit();

            Log::info('Utilisateur mis à jour avec succès', ['id' => $user->id]);

            return response()->json([
                'success' => true,
                'message' => 'Utilisateur mis à jour avec succès.',
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Erreur lors de la mise à jour de l\'utilisateur', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la mise à jour de l\'utilisateur.',
            ], 500);
        }
    }

    public function show($id)
    {
        $user = User::with(['role', 'owner', 'manager', 'seller'])->findOrFail($id);
        return response()->json($user);
    }


    public function destroy(Request $request, $id)
    {
        $user = User::findOrFail($id);

        if ($user->id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous ne pouvez pas supprimer votre propre compte.',
            ], 422);
        }

        if ($user->owner()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Un propriétaire se supprime en supprimant son entreprise.',
            ], 422);
        }

        // Vérifier si l'utilisateur est un vendeur lié à des ventes
        if (\App\Models\Sale::where('seller_id', $id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer cet utilisateur car il a effectué une ou plusieurs ventes.'
            ], 422);
        }

        // Vérifier si l'utilisateur est lié à des dépenses
        if (\App\Models\Expense::where('user_id', $id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer cet utilisateur car il a effectué une ou plusieurs dépenses.'
            ], 422);
        }

        // Vérifier si l'utilisateur est lié à des approvisionnements
        if (\App\Models\Supply::where('user_id', $id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer cet utilisateur car il a effectué un ou plusieurs approvisionnements.'
            ], 422);
        }

        // Vérifier si l'utilisateur est lié à des reçus de paiement
        if (\App\Models\PaymentReceipt::where('user_id', $id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer cet utilisateur car il a enregistré un ou plusieurs paiements.'
            ], 422);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'Utilisateur supprimé avec succès.',
        ]);
    }

    public function disable($id)
    {
        $user = User::findOrFail($id);
        $user->status = !$user->status;
        $user->save();
        return response()->json([
            'success' => true,
            'message' => $user->status ? 'Utilisateur activée avec succès.' : 'Utilisateur désactivée avec succès.',
        ]);
    }
}
