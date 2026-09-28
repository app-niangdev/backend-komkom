<?php

namespace App\Services;

use App\Models\Menu;
use App\Models\User;
use Illuminate\Support\Collection;

class MenuService
{
    /**
     * Menus actifs associés au rôle de l'utilisateur.
     */
    public function forUser(User $user): Collection
    {
        return Menu::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('roles.id', $user->role_id))
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * Vérifie si l'utilisateur peut accéder à un chemin frontend (ex. /products).
     */
    public function userCanAccessPath(User $user, string $path): bool
    {
        $normalized = '/'.trim($path, '/');
        if ($normalized === '/') {
            $normalized = '/dashboard';
        }

        $alwaysAllowed = ['/dashboard', '/profile'];
        if (in_array($normalized, $alwaysAllowed, true)) {
            return true;
        }

        return $this->forUser($user)->contains(function (Menu $menu) use ($normalized) {
            $menuUrl = '/'.trim($menu->url, '/');

            return $normalized === $menuUrl
                || str_starts_with($normalized, $menuUrl.'/');
        });
    }
}
