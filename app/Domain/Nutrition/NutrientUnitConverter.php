<?php

namespace App\Domain\Nutrition;

use App\Domain\Measurements\Exceptions\IncompatibleDimensions;
use App\Domain\Shared\Decimal;
use Brick\Math\BigDecimal;

final class NutrientUnitConverter
{
    public function convert(string|int|BigDecimal $amount, NutrientUnit $from, NutrientUnit $to): BigDecimal
    {
        $value = $amount instanceof BigDecimal ? $amount : Decimal::parse($amount);

        if ($from === $to) {
            return $value;
        }

        return match ([$from, $to]) {
            [NutrientUnit::Kilocalorie, NutrientUnit::Kilojoule] => $value->multipliedBy(EnergyConversion::KILOJOULES_PER_KILOCALORIE),
            [NutrientUnit::Kilojoule, NutrientUnit::Kilocalorie] => $value->dividedBy(EnergyConversion::KILOJOULES_PER_KILOCALORIE, Decimal::DIVISION_GUARD_SCALE, Decimal::ROUNDING_MODE),
            [NutrientUnit::Gram, NutrientUnit::Milligram] => $value->multipliedBy('1000'),
            [NutrientUnit::Milligram, NutrientUnit::Gram] => $value->dividedBy('1000', Decimal::DIVISION_GUARD_SCALE, Decimal::ROUNDING_MODE),
            default => throw new IncompatibleDimensions("Cannot convert nutrient unit {$from->value} to {$to->value}."),
        };
    }
}
