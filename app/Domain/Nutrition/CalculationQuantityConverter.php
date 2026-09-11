<?php

namespace App\Domain\Nutrition;

use App\Domain\Catalogue\CatalogueItemSource;
use App\Domain\Catalogue\CatalogueItemStatus;
use App\Domain\Catalogue\ServingAmountBasis;
use App\Domain\Measurements\CustomUnit;
use App\Domain\Measurements\Exceptions\IncompatibleDimensions;
use App\Domain\Measurements\Exceptions\NonConvertibleUnit;
use App\Domain\Measurements\MeasurementDimension;
use App\Domain\Measurements\MeasurementUnitRegistry;
use App\Domain\Measurements\StandardUnit;
use App\Domain\Measurements\UnitConverter;
use App\Domain\Shared\Decimal;
use App\Models\CatalogueItemVersion;
use Brick\Math\BigDecimal;

final class CalculationQuantityConverter
{
    public function __construct(private readonly UnitConverter $unitConverter = new UnitConverter) {}

    public function convert(
        string|int $quantity,
        StandardUnit|CustomUnit $from,
        StandardUnit|CustomUnit $to,
        ?CatalogueItemVersion $foodContext = null,
    ): QuantityConversionResult {
        if ($from instanceof CustomUnit || $to instanceof CustomUnit) {
            return QuantityConversionResult::excluded(QuantityConversionExclusionReason::CustomUnit);
        }

        try {
            return QuantityConversionResult::converted(
                $this->unitConverter->convert($quantity, $from, $to),
                $to,
            );
        } catch (IncompatibleDimensions|NonConvertibleUnit) {
            return $this->convertUsingFoodContext($quantity, $from, $to, $foodContext);
        }
    }

    private function convertUsingFoodContext(
        string|int $quantity,
        StandardUnit $from,
        StandardUnit $to,
        ?CatalogueItemVersion $foodContext,
    ): QuantityConversionResult {
        $fromDimension = MeasurementUnitRegistry::definition($from)->dimension;
        $toDimension = MeasurementUnitRegistry::definition($to)->dimension;

        if ($fromDimension === MeasurementDimension::Count && $toDimension === MeasurementDimension::Count) {
            return QuantityConversionResult::excluded(QuantityConversionExclusionReason::UnsupportedCountUnit);
        }

        $fromIsCount = $fromDimension === MeasurementDimension::Count;
        $toIsCount = $toDimension === MeasurementDimension::Count;
        $otherDimension = $fromIsCount ? $toDimension : $fromDimension;

        if ($fromIsCount === $toIsCount
            || ! in_array($otherDimension, [MeasurementDimension::Mass, MeasurementDimension::Volume], true)
        ) {
            return QuantityConversionResult::excluded(QuantityConversionExclusionReason::InvalidDimensionCombination);
        }

        $countUnit = $fromIsCount ? $from : $to;

        if (! in_array($countUnit, [StandardUnit::Item, StandardUnit::Serving], true)) {
            return QuantityConversionResult::excluded(QuantityConversionExclusionReason::UnsupportedCountUnit);
        }

        if ($foodContext === null) {
            return QuantityConversionResult::excluded(QuantityConversionExclusionReason::FoodConversionMissing);
        }

        $item = $foodContext->catalogueItem()->first();

        if ($item?->status !== CatalogueItemStatus::Approved) {
            return QuantityConversionResult::excluded(QuantityConversionExclusionReason::FoodContextNotApproved);
        }

        $factor = $this->foodFactor($foodContext, $countUnit);

        if ($factor === null) {
            return QuantityConversionResult::excluded(QuantityConversionExclusionReason::FoodConversionMissing);
        }

        [$factorQuantity, $factorUnit, $source, $basis] = $factor;

        if ($source === null) {
            return QuantityConversionResult::excluded(QuantityConversionExclusionReason::FoodConversionProvenanceMissing);
        }

        if (MeasurementUnitRegistry::definition($factorUnit)->dimension !== $otherDimension) {
            return QuantityConversionResult::excluded(QuantityConversionExclusionReason::FoodConversionMissing);
        }

        $amount = Decimal::parse($quantity);

        if ($fromIsCount) {
            $inFactorUnit = $amount->multipliedBy($factorQuantity);
            $converted = $this->unitConverter->convert((string) $inFactorUnit, $factorUnit, $to);
        } else {
            $inFactorUnit = $this->unitConverter->convert($quantity, $from, $factorUnit);
            $converted = $inFactorUnit->dividedBy(
                $factorQuantity,
                Decimal::DIVISION_GUARD_SCALE,
                Decimal::ROUNDING_MODE,
            );
        }

        return QuantityConversionResult::converted(
            $converted,
            $to,
            new FoodQuantityConversionEvidence(
                catalogueItemVersionId: (string) $foodContext->getKey(),
                source: $source,
                basis: $basis,
            ),
        );
    }

    /**
     * @return array{BigDecimal, StandardUnit, CatalogueItemSource|null, string}|null
     */
    private function foodFactor(CatalogueItemVersion $version, StandardUnit $countUnit): ?array
    {
        if ($countUnit === StandardUnit::Item) {
            if ($version->amount_per_item === null || $version->amount_per_item_unit === null) {
                return null;
            }

            return [
                Decimal::parse($version->amount_per_item),
                $version->amount_per_item_unit,
                $version->package_source,
                'amount_per_item',
            ];
        }

        if ($version->serving_amount === null
            || $version->serving_amount_unit === null
            || $version->serving_amount_basis === null
        ) {
            return null;
        }

        $source = $version->serving_amount_basis === ServingAmountBasis::Source
            ? $version->serving_source
            : $version->package_source;

        return [
            Decimal::parse($version->serving_amount),
            $version->serving_amount_unit,
            $source,
            $version->serving_amount_basis->value,
        ];
    }
}
