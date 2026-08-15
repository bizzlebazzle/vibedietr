<?php

namespace App\Domain\Recipes;

use App\Models\Recipe;
use App\Models\RecipeVersion;

final class NullRecipeFinalizationHook implements RecipeFinalizationHook
{
    public function beforeCommit(Recipe $recipe, RecipeVersion $version): void {}
}
