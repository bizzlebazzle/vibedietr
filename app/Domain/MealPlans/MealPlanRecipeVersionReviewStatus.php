<?php

namespace App\Domain\MealPlans;

enum MealPlanRecipeVersionReviewStatus: string
{
    case Pending = 'pending';
    case Updated = 'updated';
    case Retained = 'retained';
    case Inapplicable = 'inapplicable';
}
