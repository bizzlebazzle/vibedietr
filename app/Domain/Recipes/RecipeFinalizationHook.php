<?php

namespace App\Domain\Recipes;

use App\Models\Recipe;
use App\Models\RecipeVersion;

interface RecipeFinalizationHook
{
    public function beforeCommit(Recipe $recipe, RecipeVersion $version): void;
}
