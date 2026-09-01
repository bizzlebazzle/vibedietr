<?php

namespace App\Domain\Nutrition;

enum NutrientProvenance: string
{
    case Imported = 'imported';
    case ManuallySubmitted = 'manually_submitted';
    case Derived = 'derived';
    case Corrected = 'corrected';
}
