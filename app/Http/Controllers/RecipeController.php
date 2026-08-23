<?php

namespace App\Http\Controllers;

use App\Domain\Recipes\PublicRecipe;
use App\Domain\Recipes\RecipeVisibility;
use App\Domain\Recipes\RecipeVisibilityChanger;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RecipeController extends Controller
{
    public function create(): View
    {
        $this->authorize('create', Recipe::class);

        return view('recipes.create');
    }

    public function show(Request $request, int $recipe): View
    {
        $viewer = $request->user();
        $recipe = Recipe::query()
            ->visibleTo($viewer instanceof User ? $viewer : null)
            ->with('currentVersion')
            ->findOrFail($recipe);

        $this->authorize('view', $recipe);

        if ($recipe->isFinalized()) {
            return view('recipes.show', [
                'recipe' => $recipe,
                'publicRecipe' => PublicRecipe::fromCurrentVersion($recipe),
            ]);
        }

        $recipe->load(['ingredientLines', 'instructionSteps.section']);

        return view('recipes.show', ['recipe' => $recipe, 'publicRecipe' => null]);
    }

    public function edit(Recipe $recipe): View
    {
        $this->authorize('update', $recipe);

        return view('recipes.edit', compact('recipe'));
    }

    public function updateVisibility(
        Request $request,
        Recipe $recipe,
        RecipeVisibilityChanger $changer,
    ): RedirectResponse {
        $this->authorize('changeVisibility', $recipe);

        $validated = $request->validate([
            'visibility' => ['required', Rule::enum(RecipeVisibility::class)],
        ]);
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $updated = $changer->change(
            (int) $recipe->getKey(),
            RecipeVisibility::from($validated['visibility']),
            $user,
        );

        return redirect()
            ->route('recipes.show', $updated)
            ->with('status', 'Recipe visibility changed to '.$updated->getRawOriginal('visibility').'.');
    }
}
