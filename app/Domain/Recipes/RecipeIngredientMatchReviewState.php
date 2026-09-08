<?php

namespace App\Domain\Recipes;

enum RecipeIngredientMatchReviewState: string
{
    case Confirmed = 'confirmed';
    case NeedsReview = 'needs_review';
}
