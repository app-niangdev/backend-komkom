<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Manager;
use App\Models\Role;
use App\Models\Seller;
use App\Models\Store;
use App\Models\User;
use App\Services\JwtService;
use App\Services\OwnerScopeService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Équipe du propriétaire : gestionnaires et vendeurs des boutiques de SON entreprise.
 * Le gérant voit l'équipe de SA boutique ; il consulte les gestionnaires sans pouvoir
 * les modifier et ne gère (ajout, modification, activation, suppression) que les vendeurs.
 */
class OwnerTeamController extends Controller
{
    private const TEAM_ROLES = ['Manager', 'Seller'];

    public function __construct(protected OwnerScopeService $scope)
    {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:100',
            'role' => ['nullable', Rule::in(self::TEAM_ROLES)],
            'status' => 'nullable|in:0,1',
        ]);

        $storeIds = $this->scope->resolve($request)['store_ids'];

        $members = $this->teamQuery($storeIds)
            ->with(['role', 'manager.store:id,name', 'seller.store:id,name'])
            ->withCount(['sales' => fn ($q) => $q->withTrashed()])
            ->when($validated['role'] ?? null, fn ($q, $role) => $q->whereHas('role', fn ($r) => $r->where('name', $role)))
            ->when(isset($validated['status']), fn ($q) => $q->where('status', (bool) $validated['status']))
            ->when($validated['search'] ?? null, fn ($q, $s) => $q->where(fn ($sub) => $sub
                ->where('first_name', 'ILIKE', "%{$s}%")
                ->orWhere('last_name', 'ILIKE', "%{$s}%")
                ->orWhere('email', 'ILIKE', "%{$s}%")
                ->orWhere('phone_number_one', 'ILIKE', "%{$s}%")))
            ->orderBy('first_name')
            ->get();

        return response()->json([
            'data' => $members->map(fn (User $u) => $this->present($u, $request->user()))->values(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);
        abort_if(
            $validated['role'] !== 'Seller' && $this->scope->isManager($request->user()),
            403,
            'Un gérant ne peut ajouter que des vendeurs.'
        );
        $store = $this->ownedStore($request, $validated['store_id']);
        $role = Role::where('name', $validated['role'])->firstOrFail();

        $user = DB::transaction(function () use ($validated, $store, $role) {
            $user = User::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                // Mot de passe initial : à changer par l'utilisateur depuis « Mon profil »
                'password' => Hash::make('password'),
                'role_id' => $role->id,
                'type' => strtolower($role->name),
                'status' => true,
                'phone_number_one' => $validated['phone_number_one'],
                'phone_number_two' => $validated['phone_number_two'] ?? null,
                'address' => $validated['address'],
                'gender' => $validated['gender'],
                'store_id' => $store->id,
                'company_id' => $store->company_id,
                'email_verified_at' => Carbon::now(),
            ]);

            $profile = $role->name === 'Manager' ? Manager::class : Seller::class;
            $profile::create(['user_id' => $user->id, 'store_id' => $store->id]);

            return $user;
        });

        return response()->json([
            'success' => true,
            'message' => ($role->name === 'Manager' ? 'Gestionnaire' : 'Vendeur') . ' ajouté à « ' . $store->name . ' ».',
            'data' => $this->present($this->fresh($user), $request->user()),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $user = $this->manageableMember($request, $id);
        $validated = $this->validatePayload($request, $user);

        if ($validated['role'] !== $user->role->name) {
            return response()->json([
                'success' => false,
                'message' => 'Le rôle d\'un membre existant ne peut pas être modifié.',
            ], 422);
        }

        $store = $this->ownedStore($request, $validated['store_id']);

        DB::transaction(function () use ($user, $validated, $store) {
            $user->update([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'phone_number_one' => $validated['phone_number_one'],
                'phone_number_two' => $validated['phone_number_two'] ?? null,
                'address' => $validated['address'],
                'gender' => $validated['gender'],
                'store_id' => $store->id,
                'company_id' => $store->company_id,
            ]);
            ($user->manager ?? $user->seller)?->update(['store_id' => $store->id]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Membre mis à jour.',
            'data' => $this->present($this->fresh($user), $request->user()),
        ]);
    }

    public function toggleStatus(Request $request, $id, JwtService $jwt)
    {
        $user = $this->manageableMember($request, $id);
        $user->status = !$user->status;
        $user->save();

        // Un compte désactivé perd immédiatement ses sessions
        if (!$user->status) {
            $jwt->revokeAllForUser($user->id);
        }

        return response()->json([
            'success' => true,
            'message' => $user->status ? 'Compte réactivé.' : 'Compte désactivé : l\'accès est coupé.',
            'data' => $this->present($this->fresh($user), $request->user()),
        ]);
    }

    /**
     * Suppression d'un membre qui n'a encore enregistré aucune vente (même annulée) :
     * au-delà, il faut le désactiver pour conserver l'historique.
     */
    public function destroy(Request $request, $id, JwtService $jwt)
    {
        $user = $this->manageableMember($request, $id);

        if ($user->sales()->withTrashed()->exists()) {
            return response()->json([
                'success' => false,
                'message' => $user->full_name . ' a déjà enregistré des ventes : désactivez le compte plutôt que de le supprimer.',
            ], 422);
        }

        DB::transaction(function () use ($user, $jwt) {
            $jwt->revokeAllForUser($user->id);
            ($user->manager ?? $user->seller)?->delete();
            $user->delete();
        });

        return response()->json([
            'success' => true,
            'message' => ($user->role->name === 'Manager' ? 'Gestionnaire' : 'Vendeur') . ' « ' . $user->full_name . ' » supprimé.',
        ]);
    }

    /** Gestionnaires et vendeurs rattachés aux boutiques données. */
    private function teamQuery(array $storeIds): Builder
    {
        return User::query()
            ->whereHas('role', fn ($r) => $r->whereIn('name', self::TEAM_ROLES))
            ->where(fn ($q) => $q
                ->whereHas('manager', fn ($m) => $m->whereIn('store_id', $storeIds))
                ->orWhereHas('seller', fn ($s) => $s->whereIn('store_id', $storeIds)));
    }

    /** Membre de l'équipe du propriétaire (toutes ses boutiques), sinon 404. */
    private function member(Request $request, $id): User
    {
        $storeIds = $this->scope->stores($request->user())->pluck('id')->all();

        return $this->teamQuery($storeIds)->with(['role', 'manager', 'seller'])->findOrFail($id);
    }

    /** Membre que l'utilisateur connecté peut modifier : un gérant ne touche qu'aux vendeurs. */
    private function manageableMember(Request $request, $id): User
    {
        $user = $this->member($request, $id);
        abort_if(
            !$this->canManage($request->user(), $user),
            403,
            'Vous pouvez consulter les gestionnaires mais pas les modifier.'
        );

        return $user;
    }

    private function canManage(User $actor, User $member): bool
    {
        return !$this->scope->isManager($actor) || $member->role?->name === 'Seller';
    }

    private function fresh(User $user): User
    {
        return $user->fresh(['role', 'manager.store', 'seller.store'])
            ->loadCount(['sales' => fn ($q) => $q->withTrashed()]);
    }

    private function ownedStore(Request $request, $storeId): Store
    {
        $store = $this->scope->stores($request->user())->firstWhere('id', (int) $storeId);
        abort_if(!$store, 422, 'La boutique choisie n\'appartient pas à votre entreprise.');

        return $store;
    }

    private function validatePayload(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'role' => ['required', Rule::in(self::TEAM_ROLES)],
            'store_id' => 'required|integer',
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone_number_one' => 'required|string|max:20',
            'phone_number_two' => 'nullable|string|max:20',
            'address' => 'required|string|max:255',
            'gender' => 'required|in:male,female',
        ], [
            'role.required' => 'Le rôle est obligatoire.',
            'store_id.required' => 'La boutique est obligatoire.',
            'first_name.required' => 'Le prénom est obligatoire.',
            'last_name.required' => 'Le nom est obligatoire.',
            'email.required' => 'L\'email est obligatoire.',
            'email.email' => 'L\'email est invalide.',
            'email.unique' => 'Cet email est déjà utilisé.',
            'phone_number_one.required' => 'Le téléphone est obligatoire.',
            'address.required' => 'L\'adresse est obligatoire.',
            'gender.required' => 'Le genre est obligatoire.',
        ]);
    }

    private function present(User $u, User $actor): array
    {
        $store = $u->manager?->store ?? $u->seller?->store;
        $canManage = $this->canManage($actor, $u);
        $salesCount = (int) ($u->sales_count ?? 0);

        return [
            'id' => $u->id,
            'first_name' => $u->first_name,
            'last_name' => $u->last_name,
            'full_name' => $u->full_name,
            'email' => $u->email,
            'phone_number_one' => $u->phone_number_one,
            'phone_number_two' => $u->phone_number_two,
            'address' => $u->address,
            'gender' => $u->gender,
            'status' => (bool) $u->status,
            'role' => $u->role?->name,
            'store' => $store ? ['id' => $store->id, 'name' => $store->name] : null,
            'image_url' => $u->image_url,
            'sales_count' => $salesCount,
            'can_manage' => $canManage,
            'can_delete' => $canManage && $salesCount === 0,
        ];
    }
}
