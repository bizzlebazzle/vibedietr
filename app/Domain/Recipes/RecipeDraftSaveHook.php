<?php

namespace App\Domain\Recipes;

use App\Models\Recipe;

interface RecipeDraftSaveHook
{
    public function beforeCommit(Recipe $recipe): void;
}
