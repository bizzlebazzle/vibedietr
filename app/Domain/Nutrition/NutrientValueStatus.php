<?php

namespace App\Domain\Nutrition;

enum NutrientValueStatus: string
{
    case Known = 'known';
    case Missing = 'missing';
    case Trace = 'trace';
    case BelowLimit = 'below_limit';
    case Approximate = 'approximate';
    case NotSignificantSource = 'not_significant_source';
}
