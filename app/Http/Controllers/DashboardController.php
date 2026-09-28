<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('dashboard', [
            'recipeCount' => $user->recipes()->count(),
            'planCount' => $user->mealPlans()->count(),
        ]);
    }
}
