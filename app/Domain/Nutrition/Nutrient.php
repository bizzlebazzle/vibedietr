<?php

namespace App\Domain\Nutrition;

enum Nutrient: string
{
    case EnergyKcal = 'energy_kcal';
    case EnergyKj = 'energy_kj';
    case Fat = 'fat';
    case SaturatedFat = 'saturated_fat';
    case Carbohydrates = 'carbohydrates';
    case Sugars = 'sugars';
    case Fibre = 'fibre';
    case Protein = 'protein';
    case Salt = 'salt';
    case Sodium = 'sodium';
}
