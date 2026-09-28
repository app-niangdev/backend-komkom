<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RoleController extends Controller
{

    public function index()
{
    $user = Auth::user();
    $query = Role::query();

    switch ($user->role->name) {
        case 'Owner':
            $query->whereNotIn('name', ['Admin', 'Owner']);
            break;

        case 'Manager':
            $query->whereNotIn('name', ['Manager', 'Owner', 'Admin']);
            break;

        case 'Admin':
            // L'admin peut voir tous les rôles
            break;
    }

    $roles = $query->get();

    return response()->json([
        'data' => $roles
    ]);
}

    // public function index()
    // {
    //     $roles = Role::all();

    //     return response()->json(
    //         ['data' => $roles]
    //     );
    // }
}
