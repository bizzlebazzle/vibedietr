<?php

namespace App\Domain\NutritionTargets;

enum NutritionTargetType: string
{
    case Exact = 'exact';
    case Minimum = 'minimum';
    case Maximum = 'maximum';
    case Range = 'range';
}
