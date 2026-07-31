<?php

namespace App\Domain\Measurements;

use App\Domain\Measurements\Exceptions\IncompatibleDimensions;
use App\Domain\Measurements\Exceptions\NonConvertibleUnit;
use App\Domain\Shared\Decimal;
use Brick\Math\BigDecimal;

final class UnitConverter
{
    public function canConvert(StandardUnit|CustomUnit $from, StandardUnit|CustomUnit $to): bool
    {
        try {
            $this->assertConvertible($from, $to);

            return true;
        } catch (IncompatibleDimensions|NonConvertibleUnit) {
            return false;
        }
    }

    public function convert(string|int $quantity, StandardUnit|CustomUnit $from, StandardUnit|CustomUnit $to): BigDecimal
    {
        $this->assertConvertible($from, $to);
        $amount = Decimal::parse($quantity);

        if ($from === $to) {
            return $amount;
        }

        $fromDefinition = MeasurementUnitRegistry::definition($from);
        $toDefinition = MeasurementUnitRegistry::definition($to);
        $canonical = $amount->multipliedBy($fromDefinition->canonicalFactor);

        return $canonical->dividedBy(
            $toDefinition->canonicalFactor,
            Decimal::DIVISION_GUARD_SCALE,
            Decimal::ROUNDING_MODE,
        );
    }

    private function assertConvertible(StandardUnit|CustomUnit $from, StandardUnit|CustomUnit $to): void
    {
        if ($from instanceof CustomUnit || $to instanceof CustomUnit) {
            throw new NonConvertibleUnit('Custom units require an explicit future mapping before conversion.');
        }

        $fromDefinition = MeasurementUnitRegistry::definition($from);
        $toDefinition = MeasurementUnitRegistry::definition($to);

        if ($fromDefinition->dimension !== $toDefinition->dimension) {
            throw new IncompatibleDimensions("Cannot convert {$fromDefinition->dimension->value} to {$toDefinition->dimension->value}.");
        }

        if ($from === $to) {
            return;
        }

        if ($fromDefinition->canonicalFactor === null || $toDefinition->canonicalFactor === null) {
            throw new NonConvertibleUnit('Count units only support identity conversion of the same unit.');
        }
    }
}
