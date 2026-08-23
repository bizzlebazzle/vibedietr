<?php

namespace App\Domain\Recipes;

use App\Models\Recipe;
use App\Models\RecipeRemixLineage;

interface RecipeRemixCreationHook
{
    public function afterIngredientCopy(Recipe $remix): void;

    public function afterInstructionCopy(Recipe $remix): void;

    public function afterLineageCreation(Recipe $remix, RecipeRemixLineage $lineage): void;
}
