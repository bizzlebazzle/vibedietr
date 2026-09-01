<?php

namespace App\Domain\Nutrition;

enum NutrientDerivation: string
{
    case EnergyKcalFromKj = 'energy_kcal_from_kj';
    case EnergyKjFromKcal = 'energy_kj_from_kcal';
}
