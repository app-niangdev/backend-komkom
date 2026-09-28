<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Owner;
use App\Models\Company;
use App\Models\Role;
use App\Models\Store;
use App\Services\FileValidationService;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class CompanyOwnerController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->input('perPage', 10);
        $search = $request->input('search');
        $query = Company::with(['owner.user'])->withCount('stores');

        // 🔎 Recherche textuelle
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                    ->orWhere('short_name', 'ILIKE', "%{$search}%")
                    ->orWhere('slogan', 'ILIKE', "%{$search}%")
                    ->orWhere('email', 'ILIKE', "%{$search}%")
                    ->orWhere('phone_one', 'ILIKE', "%{$search}%")
                    ->orWhere('phone_two', 'ILIKE', "%{$search}%")
                    ->orWhereHas('owner.user', function ($userQuery) use ($search) {
                        $userQuery->where('first_name', 'ILIKE', "%{$search}%")
                                ->orWhere('last_name', 'ILIKE', "%{$search}%")
                                ->orWhere('email', 'ILIKE', "%{$search}%");
                    });
            });
        }

        $companies = $query->orderByDesc('id')->paginate($perPage, ['*']);

        return response()->json([
            'data' => $companies->items(),
            'meta' => [
                'current_page' => $companies->currentPage(),
                'per_page' => $companies->perPage(),
                'total' => $companies->total(),
                'last_page' => $companies->lastPage(),
            ]
        ]);
    }

    public function show($id)
    {
        $company = Company::with(['owner.user'])->withCount('stores')->find($id);

        if (!$company) {
            return response()->json([
                'success' => false,
                'message' => 'Entreprise non trouvée.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $company,
        ]);
    }

    public function store(Request $request)
    {
        $messages = [
            'first_name.required' => 'Le prénom est obligatoire.',
            'short_name.required' => 'Le nom abrégé est obligatoire.',
            'last_name.required' => 'Le nom est obligatoire.',
            'email.required' => 'L\'email est obligatoire.',
            'email.email' => 'Le format de l\'email est invalide.',
            'email.unique' => "Cet email d'utilisateur est déjà utilisé.",
            'phone_number_one.required' => 'Le premier numéro de téléphone est obligatoire.',
            'gender.required' => 'Le genre est obligatoire.',
            'address.required' => 'L\'adresse du propriétaire est obligatoire.',
            'head_office_address.required' => 'L\'adresse du siège est obligatoire.',
            'name.required' => 'Le nom de l\'entreprise est obligatoire.',
            'email_company.required' => 'L\'email l\'entreprise est obligatoire.',
            'email_company.email' => 'L\'email de l\'entreprise est invalide.',
            'email_company.unique' => "L'email de l'entreprise est déjà utilisé.",
            'phone_one.required' => 'Le numéro principal de l\'entreprise est obligatoire.',
        ];

        $validated = $request->validate([
            // USER OWNER
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'phone_number_one' => 'required|string|max:20',
            'phone_number_two' => 'nullable|string|max:20',
            'address' => 'required|string|max:255',
            'gender' => 'required|string|in:male,female',

            // COMPANY
            'name' => 'required|string|max:255',
            'short_name' => 'required|string|max:50',
            'slogan' => 'nullable|string|max:255',
            'head_office_address' => 'required|string|max:255',
            'email_company' => 'required|email|max:255|unique:companies,email',
            'phone_one' => 'required|string|max:20',
            'phone_two' => 'nullable|string|max:20',
        ], $messages);

        try {
            DB::beginTransaction();
            $ownerRole = Role::firstOrCreate(['name' => 'Owner']);
            // 1️⃣ Création de l'utilisateur
            $user = User::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'password' => Hash::make('password'),
                'status' => true,
                'role_id' => $ownerRole->id,
                'type' => 'owner',
                'phone_number_one' => $validated['phone_number_one'],
                'phone_number_two' => $validated['phone_number_two'] ?? null,
                'address' => $validated['address'] ?? null,
                'gender' => $validated['gender'],
                'email_verified_at' => Carbon::now()
            ]);

            // 2️⃣ Création du propriétaire (Owner)
            $owner = Owner::create([
                'user_id' => $user->id,
            ]);

            // 3️⃣ Création de la société (Company)
            $company = Company::create([
                'owner_id' => $owner->id,
                'name' => $validated['name'],
                'short_name' => $validated['short_name'] ?? null,
                'slogan' => $validated['slogan'] ?? null,
                'head_office_address' => $validated['head_office_address'] ?? null,
                'email' => $validated['email_company'] ?? null,
                'phone_one' => $validated['phone_one'],
                'phone_two' => $validated['phone_two'] ?? null,
            ]);

            // 3️⃣ Création d'une boutique par defaut (Store), avec sa période d'essai
            $defaultStore = Store::create([
                'company_id' => $company->id,
                'name' => $company->name,
                'address' => $company->head_office_address,
                'phone_one' => $company->phone_one,
                'phone_two' => $company->phone_two,
                'email' => $company->email,
                'active' => true
            ]);
            app(SubscriptionService::class)->createTrial($defaultStore, $request->user()?->id);

            $user->company_id = $company->id;
            $user->update();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Entreprise créée avec succès.',
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::info($e);
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de l\'entreprise.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $messages = [
            'first_name.required' => 'Le prénom est obligatoire.',
            'last_name.required' => 'Le nom est obligatoire.',
            'email.required' => 'L\'email est obligatoire.',
            'email.email' => 'Le format de l\'email est invalide.',
            'email.unique' => "Cet email d'utilisateur est déjà utilisé.",
            'phone_number_one.required' => 'Le premier numéro de téléphone est obligatoire.',
            'gender.required' => 'Le genre est obligatoire.',
            'address.required' => 'L\'adresse du propriétaire est obligatoire.',
            'head_office_address.required' => 'L\'adresse du siège est obligatoire.',
            'name.required' => 'Le nom de l\'entreprise est obligatoire.',
            'short_name.required' => 'Le nom abrégé est obligatoire.',
            'email_company.required' => 'L\'email de l\'entreprise est obligatoire.',
            'email_company.email' => 'Le format de l\'email de l\'entreprise est invalide.',
            'email_company.unique' => "L'email de l'entreprise est déjà utilisé.",
            'phone_one.required' => 'Le numéro principal de l\'entreprise est obligatoire.',
        ];

        // 🔹 On récupère la société à modifier
        $company = Company::with(['owner.user'])->findOrFail($id);
        $owner = $company->owner;
        $user = $owner->user;

        $validated = $request->validate([
            // USER OWNER
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'phone_number_one' => 'required|string|max:20',
            'phone_number_two' => 'nullable|string|max:20',
            'address' => 'required|string|max:255',
            'gender' => 'required|string|in:male,female',

            // COMPANY
            'name' => 'required|string|max:255',
            'short_name' => 'required|string|max:50',
            'slogan' => 'nullable|string|max:255',
            'head_office_address' => 'required|string|max:255',
            'email_company' => 'required|email|max:255|unique:companies,email,' . $company->id,
            'phone_one' => 'required|string|max:20',
            'phone_two' => 'nullable|string|max:20',
        ], $messages);

        try {
            DB::beginTransaction();

            // 1️⃣ Mise à jour de l'utilisateur
            $user->update([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'phone_number_one' => $validated['phone_number_one'],
                'phone_number_two' => $validated['phone_number_two'] ?? null,
                'address' => $validated['address'],
                'gender' => $validated['gender'],
            ]);

            // 2️⃣ Mise à jour de la société
            $company->update([
                'name' => $validated['name'],
                'short_name' => $validated['short_name'] ?? null,
                'slogan' => $validated['slogan'] ?? null,
                'head_office_address' => $validated['head_office_address'] ?? null,
                'email' => $validated['email_company'] ?? null,
                'phone_one' => $validated['phone_one'],
                'phone_two' => $validated['phone_two'] ?? null,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Entreprise mise à jour avec succès.',
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour de l\'entreprise.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Supprime (soft delete) l'entreprise et ses boutiques, et désactive ses utilisateurs.
     */
    public function destroy($id)
    {
        $company = Company::find($id);

        if (!$company) {
            return response()->json([
                'success' => false,
                'message' => 'Entreprise non trouvée.',
            ], 404);
        }

        try {
            DB::beginTransaction();

            User::where('company_id', $company->id)->update(['status' => false]);
            $company->stores()->delete();
            $company->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Entreprise supprimée avec succès.',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de l\'entreprise.',
            ], 500);
        }
    }

    public function updateCompany(Request $request, $id, FileValidationService $fileValidator)
    {
        $messages = [
            'name.required' => 'Le nom de l\'entreprise est obligatoire.',
            'short_name.required' => 'Le nom abrégé de l\'entreprise est obligatoire.',
            'email_company.required' => 'L\'email de l\'entreprise est obligatoire.',
            'email_company.email' => 'Le format de l\'email de l\'entreprise est invalide.',
            'email_company.unique' => "Cet email d'entreprise est déjà utilisé.",
            'phone_one.required' => 'Le numéro principal de l\'entreprise est obligatoire.',
            'phone_one.max' => 'Le numéro principal ne doit pas dépasser 20 caractères.',
            'phone_two.max' => 'Le second numéro ne doit pas dépasser 20 caractères.',
            'head_office_address.max' => 'L\'adresse du siège social ne doit pas dépasser 255 caractères.',
            'slogan.max' => 'Le slogan ne doit pas dépasser 255 caractères.',
            'primary_color.regex' => 'La couleur primaire doit être un code hexadécimal valide (#RRGGBB)',
            'secondary_color.regex' => 'La couleur secondaire doit être un code hexadécimal valide (#RRGGBB)',
        ];

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'short_name' => 'required|string|max:50',
            'slogan' => 'nullable|string|max:255',
            'head_office_address' => 'nullable|string|max:255',
            'email_company' => 'required|email|max:255|unique:companies,email,' . $id,
            'phone_one' => 'required|string|max:20',
            'phone_two' => 'nullable|string|max:20',
            'primary_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'secondary_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
        ], $messages);

        try {
            DB::beginTransaction();

            // 🔍 Récupération de l'entreprise
            $company = Company::findOrFail($id);

            // 🔐 Vérification du propriétaire AVANT toute écriture (logo compris)
            if (Auth::user()->owner?->id !== $company->owner_id) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Vous n\'êtes pas autorisé à modifier cette entreprise.',
                ], 403);
            }

            if ($request->hasFile('logo')) {
                $fileValidator->validateAndStoreFile($request->file('logo'), $company, 'logo');
            }

            // ✏️ Mise à jour
            $company->update([
                'name' => $validated['name'],
                'short_name' => $validated['short_name'],
                'slogan' => $validated['slogan'] ?? null,
                'head_office_address' => $validated['head_office_address'] ?? null,
                'email' => $validated['email_company'],
                'phone_one' => $validated['phone_one'],
                'phone_two' => $validated['phone_two'] ?? null,
                'primary_color' => $validated['primary_color'] ?? $company->primary_color,
                'secondary_color' => $validated['secondary_color'] ?? $company->secondary_color,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Les informations ont été mises à jour avec succès.',
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation.',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error($e);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la mise à jour de l\'entreprise.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
