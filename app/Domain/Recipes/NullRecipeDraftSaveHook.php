<?php

namespace App\Domain\Recipes;

use App\Models\Recipe;

final class NullRecipeDraftSaveHook implements RecipeDraftSaveHook
{
    public function beforeCommit(Recipe $recipe): void
    {
        // Tests replace this no-op hook to verify transactional rollback.
    }
}
