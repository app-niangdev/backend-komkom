<?php

namespace App\Http\Controllers;

use App\Exceptions\RefreshTokenReuseException;
use App\Http\Resources\CompanyResource;
use App\Http\Resources\ManagerResource;
use App\Http\Resources\OwnerResource;
use App\Http\Resources\SellerResource;
use App\Http\Resources\StoreResource;
use App\Http\Resources\UserResource;
use App\Models\Manager;
use App\Models\Owner;
use App\Models\Seller;
use App\Models\User;
use App\Http\Resources\MenuResource;
use App\Services\AuthCookieService;
use App\Services\JwtService;
use App\Services\LoginChallengeService;
use App\Services\LoginSecurityService;
use App\Services\MenuService;
use App\Services\SecurityLogger;
use App\Services\SubscriptionService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        protected JwtService $jwtService,
        protected AuthCookieService $cookieService,
        protected LoginSecurityService $securite,
        protected LoginChallengeService $challenges,
        protected SecurityLogger $journal,
        protected MenuService $menuService,
        protected SubscriptionService $subscriptions,
    ) {
    }

    public function login(Request $request)
    {
        try {
            $credentials = $request->validate([
                'email' => 'required|email|max:255',
                'password' => 'required|string|min:8',
                'remember_me' => 'nullable|boolean',
            ]);

            $remember = isset($credentials['remember_me']) && $credentials['remember_me'] === true;
            $ip = $request->ip();

            $user = User::where('email', $credentials['email'])->first();

            if (!$user || !Hash::check($credentials['password'], $user->password)) {
                $etat = $this->securite->enregistrerEchec($ip);
                $seuil = (int) config('login_security.max_attempts', 5);

                return response()->json([
                    'status' => false,
                    'code' => 'INVALID_CREDENTIALS',
                    'message' => 'Email ou mot de passe incorrect',
                    'attempts_left' => max(0, $seuil - $etat->failed_attempts),
                ], 401);
            }

            if (!$user->status) {
                $this->securite->enregistrerEchec($ip);

                $this->journal->log(SecurityLogger::LOGIN_FAILED, $user->id, [
                    'reason' => 'account_disabled',
                ]);

                return response()->json([
                    'status' => false,
                    'code' => 'ACCOUNT_DISABLED',
                    'message' => 'Votre compte a été suspendu',
                    'isDisabled' => true
                ], 403);
            }

            // Boutique(s) sans abonnement en cours : connexion refusée avec les détails
            if ($blocking = $this->subscriptions->blockingStatuses($user)) {
                $this->journal->log(SecurityLogger::LOGIN_FAILED, $user->id, [
                    'reason' => 'subscription_expired',
                ]);

                return $this->subscriptions->expiredResponse($blocking);
            }

            $sortaitDeBlocage = $this->securite->aEteBloquee($ip);

            if ($sortaitDeBlocage) {
                $this->journal->log(SecurityLogger::IP_BLOCK_EXPIRED, $user->id);

                $challenge = $this->challenges->ouvrir($user, $ip, LoginChallengeService::RAISON_BLOCAGE);

                return response()->json([
                    'status' => true,
                    'code' => 'OTP_REQUIRED',
                    'message' => 'Un code de vérification a été envoyé par e-mail.',
                    'data' => [
                        'challenge_token' => $challenge->challenge_token,
                        'expires_in' => (int) config('login_security.otp.ttl', 300),
                    ],
                ]);
            }

            $this->securite->reinitialiser($ip);
            $this->journal->log(SecurityLogger::LOGIN_SUCCESS, $user->id);

            return $this->issueTokensResponse($user, $remember, $request);

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Login error: ' . $e->getMessage(), [
                'email' => $request->email ?? 'unknown',
                'ip' => $request->ip()
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Une erreur est survenue lors de la connexion. Veuillez réessayer.',
            ], 500);
        }
    }

    public function verifyLoginOtp(Request $request)
    {
        $data = $request->validate([
            'challenge_token' => 'required|string',
            'code' => 'required|string',
            'remember_me' => 'nullable|boolean',
        ]);

        $resultat = $this->challenges->verifier($data['challenge_token'], $data['code']);

        if (!$resultat['ok']) {
            return response()->json([
                'status' => false,
                'code' => $resultat['code'],
                'message' => $resultat['message'],
            ], 401);
        }

        // Le code valide confirme l'identité : l'IP n'a plus a etre traitee
        // comme sortant d'un blocage, sans quoi chaque connexion suivante
        // redemanderait un OTP indefiniment.
        $this->securite->reinitialiser($request->ip());
        $this->journal->log(SecurityLogger::LOGIN_COMPLETED, $resultat['user']->id, [
            'two_factor' => false,
            'via_otp' => true,
        ]);

        // L'abonnement a pu expirer entre la demande de code et sa saisie
        if ($blocking = $this->subscriptions->blockingStatuses($resultat['user'])) {
            return $this->subscriptions->expiredResponse($blocking);
        }

        $remember = isset($data['remember_me']) && $data['remember_me'] === true;

        return $this->issueTokensResponse($resultat['user'], $remember, $request);
    }

    public function resendLoginOtp(Request $request)
    {
        $data = $request->validate([
            'challenge_token' => 'required|string',
        ]);

        $resultat = $this->challenges->renvoyer($data['challenge_token']);

        if (!$resultat['ok']) {
            return response()->json([
                'status' => false,
                'code' => $resultat['code'],
                'message' => $resultat['message'],
            ], 429);
        }

        return response()->json([
            'status' => true,
            'message' => 'Un nouveau code a été envoyé.',
        ]);
    }

    public function refresh(Request $request)
    {
        $parsed = $this->cookieService->parse($request->cookie(config('jwt.cookie_name')));

        if (!$parsed) {
            return response()->json([
                'status' => false,
                'message' => 'Session expirée, veuillez vous reconnecter',
            ], 401)->withCookie($this->cookieService->forget());
        }

        try {
            $rotated = $this->jwtService->rotateRefreshToken($parsed['rt'], false, $request);
        } catch (RefreshTokenReuseException) {
            Log::warning('Refresh token reuse detected', ['ip' => $request->ip()]);

            return response()->json([
                'status' => false,
                'message' => 'Session expirée, veuillez vous reconnecter',
            ], 401)->withCookie($this->cookieService->forget());
        }

        $accessToken = $this->jwtService->issueAccessToken($rotated['user']);
        $ttlMinutes = now()->diffInMinutes($rotated['model']->expires_at);

        $cookie = $this->cookieService->make($accessToken, $rotated['raw'], (int) $ttlMinutes);

        return response()->json(['status' => true])->withCookie($cookie);
    }

    public function logout(Request $request)
    {
        $parsed = $this->cookieService->parse($request->cookie(config('jwt.cookie_name')));

        if ($parsed) {
            $this->jwtService->revokeRefreshToken($parsed['rt']);
        }

        return response()
            ->json(['message' => 'Déconnexion réussie ...', 'status' => true])
            ->withCookie($this->cookieService->forget());
    }

    public function authenticate(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json([
                    'isAuthenticated' => false,
                    'status_otp' => false
                ]);
            }

            $user = User::with('role', 'media')->find(Auth::id());
            $roleName = ucfirst(strtolower($user->role->name));

            $responseData = [
                'isAuthenticated' => true,
                'user' => new UserResource($user),
                'menus' => MenuResource::collection($this->menuService->forUser($user))->resolve(),
                // Échéances proches (et, pour un propriétaire, boutiques expirées) à signaler
                'subscription_alerts' => $this->subscriptions->alertsForUser($user),
                'subscription_warning_days' => $this->subscriptions->warningDays(),
            ];

            // Ajouter les données spécifiques au rôle
            $roleData = $this->getRoleSpecificData($user, $roleName);

            if ($roleData) {
                $responseData = array_merge($responseData, $roleData);
            }

            return response()->json($responseData, 200, [], JSON_UNESCAPED_SLASHES);

        } catch (Exception $e) {
            return response()->json([
                "message" => "Échec d'authentification",
                "error" => $e->getMessage()
            ], 400);
        }
    }

    private function issueTokensResponse(User $user, bool $remember, Request $request)
    {
        $accessToken = $this->jwtService->issueAccessToken($user);
        $refreshToken = $this->jwtService->issueRefreshToken($user, $remember, null, $request);

        $ttlMinutes = $remember ? config('jwt.refresh_ttl_remember') : config('jwt.refresh_ttl');

        $cookie = $this->cookieService->make($accessToken, $refreshToken['raw'], (int) $ttlMinutes);

        $fullName = $user->first_name . ' ' . $user->last_name;

        return response()->json([
            'status' => true,
            'message' => 'Bienvenue à nouveau ' . $fullName,
        ])->withCookie($cookie);
    }

    /**
     * Récupère les données spécifiques selon le rôle
     */
    private function getRoleSpecificData(User $user, string $roleName): ?array
    {
        return match($roleName) {
            'Owner' => $this->getOwnerData($user),
            'Manager' => $this->getManagerData($user),
            'Seller' => $this->getSellerData($user),
            default => null,
        };
    }

    /**
     * Récupère les données pour un Owner
     * Retourne: User, Owner, Company, Stores
     */
    private function getOwnerData(User $user): array
    {
        $owner = Owner::where('user_id', $user->id)
            ->with([
                'company' => function($query) {
                    $query->with(['stores', 'media']);
                }
            ])
            ->first();

        return [
            'owner' => new OwnerResource($owner),
            'company' => new CompanyResource($owner->company),
            'stores' => StoreResource::collection($owner->company->stores ?? [])
        ];
    }

    /**
     * Récupère les données pour un Manager
     * Retourne: User, Manager, Company, Store_manager
     */
    private function getManagerData(User $user): array
    {
        $manager = Manager::where('user_id', $user->id)
            ->with(['store.company.media'])
            ->first();

        return [
            'manager' => new ManagerResource($manager),
            'company' => new CompanyResource($manager->store->company ?? null),
            'store' => new StoreResource($manager->store ?? null)
        ];
    }

    /**
     * Récupère les données pour un Seller
     * Retourne: User, Seller, Company, Store
     */
    private function getSellerData(User $user): array
    {
        $seller = Seller::where('user_id', $user->id)
            ->with(['store.company.media'])
            ->first();

        return [
            'seller' => new SellerResource($seller),
            'company' => new CompanyResource($seller->store->company ?? null),
            'store' => new StoreResource($seller->store ?? null)
        ];
    }
}
