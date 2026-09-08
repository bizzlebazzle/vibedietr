<?php

namespace App\Domain\Recipes;

use App\Domain\Catalogue\CatalogueReadQuery;
use App\Models\Recipe;
use App\Models\RecipeIngredientLineMatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RecipeIngredientMatchManager
{
    public function __construct(private readonly CatalogueReadQuery $catalogue) {}

    public function select(int $recipeId, int $lineId, int $catalogueItemId, string $catalogueVersionId, User $actor): RecipeIngredientLineMatch
    {
        return DB::transaction(function () use ($recipeId, $lineId, $catalogueItemId, $catalogueVersionId, $actor): RecipeIngredientLineMatch {
            $recipe = Recipe::query()->lockForUpdate()->findOrFail($recipeId);
            Gate::forUser($actor)->authorize('update', $recipe);
            $line = $recipe->ingredientLines()->lockForUpdate()->findOrFail($lineId);
            $version = $this->catalogue->findSelectableCurrentVersion($actor, $catalogueItemId, $catalogueVersionId, lock: true);

            if ($version === null) {
                throw ValidationException::withMessages([
                    'catalogue_match' => 'That catalogue result is no longer selectable. Search again before matching.',
                ]);
            }

            $match = $line->catalogueMatch()->lockForUpdate()->first() ?? new RecipeIngredientLineMatch;
            $match->forceFill([
                'catalogue_item_version_id' => $version->getKey(),
                'selected_by_user_id' => $actor->getKey(),
                'provenance' => RecipeIngredientMatchProvenance::ManuallySelectedByCreator,
                'review_state' => RecipeIngredientMatchReviewState::Confirmed,
            ]);
            $match->ingredientLine()->associate($line);
            $match->save();

            return $match->fresh(['catalogueItemVersion.catalogueItem']);
        }, 3);
    }

    public function clear(int $recipeId, int $lineId, User $actor): void
    {
        DB::transaction(function () use ($recipeId, $lineId, $actor): void {
            $recipe = Recipe::query()->lockForUpdate()->findOrFail($recipeId);
            Gate::forUser($actor)->authorize('update', $recipe);
            $line = $recipe->ingredientLines()->lockForUpdate()->findOrFail($lineId);
            $line->catalogueMatch()->lockForUpdate()->delete();
        }, 3);
    }
}
