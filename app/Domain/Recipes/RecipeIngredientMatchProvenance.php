<?php

namespace App\Domain\Recipes;

enum RecipeIngredientMatchProvenance: string
{
    case ManuallySelectedByCreator = 'manually_selected_by_creator';
    case OwnerConfirmedReplacement = 'owner_confirmed_replacement';
    case AutomaticallySelected = 'automatically_selected';
}
