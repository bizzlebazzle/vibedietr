<?php

namespace App\Domain\MealPlans;

enum MealPlanType: string
{
    case Reusable = 'reusable';
    case Dated = 'dated';
}
