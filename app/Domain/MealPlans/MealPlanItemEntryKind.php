<?php

namespace App\Domain\MealPlans;

enum MealPlanItemEntryKind: string
{
    case Catalogue = 'catalogue';
    case OneOff = 'one_off';
}
