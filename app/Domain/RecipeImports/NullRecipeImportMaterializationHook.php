<?php

namespace App\Domain\RecipeImports;

use App\Models\Recipe;
use App\Models\RecipeImport;

final class NullRecipeImportMaterializationHook implements RecipeImportMaterializationHook
{
    public function beforeCommit(RecipeImport $import, Recipe $recipe): void {}
}
