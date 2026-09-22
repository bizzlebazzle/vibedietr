<?php

namespace App\Domain\MealPlans;

enum MealPlanVisibility: string
{
    case Private = 'private';
    case Public = 'public';
    case RetainedUnlisted = 'retained_unlisted';
}
