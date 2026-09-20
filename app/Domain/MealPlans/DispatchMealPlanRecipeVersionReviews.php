<?php

namespace App\Domain\MealPlans;

use App\Domain\Recipes\RecipeFinalizationHook;
use App\Jobs\CreateMealPlanRecipeVersionReviews;
use App\Models\Recipe;
use App\Models\RecipeVersion;

final class DispatchMealPlanRecipeVersionReviews implements RecipeFinalizationHook
{
    public function beforeCommit(Recipe $recipe, RecipeVersion $version): void
    {
        if ($version->version_number > 1) {
            CreateMealPlanRecipeVersionReviews::dispatch($version->getKey());
        }
    }
}
