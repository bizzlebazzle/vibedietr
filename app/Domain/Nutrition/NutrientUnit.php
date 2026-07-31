<?php

namespace App\Domain\Nutrition;

enum NutrientUnit: string
{
    case Kilocalorie = 'kcal';
    case Kilojoule = 'kj';
    case Gram = 'g';
    case Milligram = 'mg';

    public function symbol(): string
    {
        return match ($this) {
            self::Kilocalorie => 'kcal',
            self::Kilojoule => 'kJ',
            self::Gram => 'g',
            self::Milligram => 'mg',
        };
    }
}
