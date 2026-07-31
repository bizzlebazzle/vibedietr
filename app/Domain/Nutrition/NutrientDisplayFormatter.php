<?php

namespace App\Domain\Nutrition;

use App\Domain\Shared\Decimal;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

final readonly class NutrientDisplayFormatter
{
    public function __construct(private NutrientUnitConverter $converter = new NutrientUnitConverter) {}

    public function format(
        Nutrient $nutrient,
        ?string $canonicalAmount,
        NutrientValueStatus $status = NutrientValueStatus::Known,
        ?string $canonicalLimit = null,
    ): string {
        $definition = NutrientRegistry::definition($nutrient);

        return match ($status) {
            NutrientValueStatus::Missing => 'Not available',
            NutrientValueStatus::Trace => 'Trace',
            NutrientValueStatus::NotSignificantSource => 'Not a significant source',
            NutrientValueStatus::BelowLimit => $this->formatLimit($definition, $canonicalLimit),
            NutrientValueStatus::Known,
            NutrientValueStatus::Approximate => $this->formatKnown($definition, $canonicalAmount),
        };
    }

    private function formatKnown(NutrientDefinition $definition, ?string $canonicalAmount): string
    {
        if ($canonicalAmount === null) {
            throw new InvalidArgumentException('Known and approximate nutrient values require an amount.');
        }

        $canonical = Decimal::parse($canonicalAmount);
        $display = $this->toDisplayUnit($definition, $canonical);
        $rounded = $display->toScale($definition->displayPrecision, $definition->displayRounding);

        if ($display->isPositive() && $rounded->isZero()) {
            $resolution = BigDecimal::one()->withPointMovedLeft($definition->displayPrecision);

            return '<'.$resolution.' '.$definition->preferredDisplayUnit->symbol();
        }

        return $rounded.' '.$definition->preferredDisplayUnit->symbol();
    }

    private function formatLimit(NutrientDefinition $definition, ?string $canonicalLimit): string
    {
        if ($canonicalLimit === null) {
            return 'Trace';
        }

        $displayLimit = $this->toDisplayUnit($definition, Decimal::parse($canonicalLimit));
        $limit = $this->withoutInsignificantFractionalZeros((string) $displayLimit);

        return '<'.$limit.' '.$definition->preferredDisplayUnit->symbol();
    }

    private function toDisplayUnit(NutrientDefinition $definition, BigDecimal $amount): BigDecimal
    {
        return $this->converter->convert(
            $amount,
            $definition->canonicalStorageUnit,
            $definition->preferredDisplayUnit,
        );
    }

    private function withoutInsignificantFractionalZeros(string $value): string
    {
        if (! str_contains($value, '.')) {
            return $value;
        }

        return rtrim(rtrim($value, '0'), '.');
    }
}
