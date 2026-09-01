<?php

namespace App\Domain\Nutrition;

enum NutrientNormalizationWarning: string
{
    case EnergySourceConflict = 'energy_source_conflict';
    case SourcePrecisionReduced = 'source_precision_reduced';
}
