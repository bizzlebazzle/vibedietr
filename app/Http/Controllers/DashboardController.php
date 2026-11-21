<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
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
            'recipeCount' => Recipe::query()->where('user_id', $user->getKey())->finalized()->count(),
            'draftCount' => Recipe::query()->where('user_id', $user->getKey())->withDraft()->count(),
            'planCount' => $user->mealPlans()->count(),
        ]);
    }
}
