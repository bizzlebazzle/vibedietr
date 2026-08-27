<?php

namespace App\Domain\RecipeImports;

use App\Models\Recipe;
use App\Models\RecipeImport;

interface RecipeImportMaterializationHook
{
    public function beforeCommit(RecipeImport $import, Recipe $recipe): void;
}
