<?php

namespace App\Http\Controllers;

use App\Domain\Nutrition\NutrientRegistry;
use App\Domain\Nutrition\RecipeNutritionOverrideManager;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RecipeNutritionOverrideController extends Controller
{
    public function update(Request $request, Recipe $recipe, RecipeNutritionOverrideManager $manager): RedirectResponse
    {
        $this->authorize('overrideNutrition', $recipe);
        $validated = $request->validate($this->rules());
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(403);
        }
        try {
            $manager->save((int) $recipe->getKey(), $validated['source_version_id'],
                $validated['nutrients'], $validated['note'] ?? null, $actor);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['nutrients' => $exception->getMessage()]);
        }

        return back()->with('status', 'Recipe nutrition override saved.');
    }

    public function destroy(Request $request, Recipe $recipe, RecipeNutritionOverrideManager $manager): RedirectResponse
    {
        $this->authorize('overrideNutrition', $recipe);
        $validated = $request->validate(['source_version_id' => ['required', 'ulid'],
            'note' => ['nullable', 'string', 'max:500']]);
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(403);
        }
        $manager->remove((int) $recipe->getKey(), $validated['source_version_id'], $validated['note'] ?? null, $actor);

        return back()->with('status', 'Recipe nutrition override removed.');
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        $keys = implode(',', NutrientRegistry::stableIdentifiers());
        $rules = ['source_version_id' => ['required', 'ulid'], 'nutrients' => ['required', 'array:'.$keys],
            'note' => ['nullable', 'string', 'max:500']];
        foreach (NutrientRegistry::stableIdentifiers() as $nutrient) {
            $rules['nutrients.'.$nutrient] = ['nullable', 'regex:/^\+?(?:0|[1-9]\d*)(?:\.\d+)?$/'];
        }

        return $rules;
    }
}
