<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use Illuminate\Contracts\View\View;

class RecipeController extends Controller
{
    public function create(): View
    {
        $this->authorize('create', Recipe::class);

        return view('recipes.create');
    }

    public function show(Recipe $recipe): View
    {
        $this->authorize('view', $recipe);

        return view('recipes.show', compact('recipe'));
    }

    public function edit(Recipe $recipe): View
    {
        $this->authorize('update', $recipe);

        return view('recipes.edit', compact('recipe'));
    }
}
