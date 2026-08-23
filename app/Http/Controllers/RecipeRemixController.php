<?php

namespace App\Http\Controllers;

use App\Domain\Recipes\RecipeRemixCreator;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RecipeRemixController extends Controller
{
    public function store(Request $request, int $recipe, RecipeRemixCreator $creator): RedirectResponse
    {
        $validated = $request->validate([
            'source_version_id' => ['required', 'ulid'],
            'operation_id' => ['required', 'ulid'],
            'user_id' => ['prohibited'],
            'owner_id' => ['prohibited'],
            'creator_id' => ['prohibited'],
            'source_recipe_id' => ['prohibited'],
            'source_version_number' => ['prohibited'],
            'source_creator_user_id' => ['prohibited'],
            'remix_recipe_id' => ['prohibited'],
        ]);
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $remix = $creator->create(
            $recipe,
            $validated['source_version_id'],
            $validated['operation_id'],
            $user,
        );

        return redirect()
            ->route('recipes.edit', $remix)
            ->with('status', 'Independent private remix created.');
    }
}
