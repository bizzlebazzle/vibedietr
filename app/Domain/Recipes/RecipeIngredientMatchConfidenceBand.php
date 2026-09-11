<?php

namespace App\Domain\Recipes;

enum RecipeIngredientMatchConfidenceBand: string
{
    case Reviewable = 'reviewable';
    case High = 'high';
}
