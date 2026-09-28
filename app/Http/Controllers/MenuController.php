<?php

namespace App\Http\Controllers;

use App\Http\Resources\MenuResource;
use App\Services\MenuService;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    public function __construct(protected MenuService $menus)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        return response()->json(
            MenuResource::collection($this->menus->forUser($user))->resolve()
        );
    }
}
