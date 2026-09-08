<?php

namespace App\Domain\Recipes;

enum RecipeIngredientMatchProvenance: string
{
    case ManuallySelectedByCreator = 'manually_selected_by_creator';
}
