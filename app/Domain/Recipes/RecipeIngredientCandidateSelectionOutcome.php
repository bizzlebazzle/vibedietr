<?php

namespace App\Domain\Recipes;

enum RecipeIngredientCandidateSelectionOutcome: string
{
    case Selected = 'selected';
    case NoEligibleCandidates = 'no_eligible_candidates';
    case BelowThreshold = 'below_threshold';
    case TopScoreTie = 'top_score_tie';
}
