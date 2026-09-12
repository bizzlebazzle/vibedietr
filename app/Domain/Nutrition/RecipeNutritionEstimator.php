<?php

namespace App\Domain\Nutrition;

use App\Domain\Measurements\CustomUnit;
use App\Domain\Measurements\StandardUnit;
use App\Domain\Shared\Decimal;
use App\Models\CatalogueItemVersion;
use App\Models\CatalogueNutrientValue;
use App\Models\Recipe;
use App\Models\RecipeIngredientLine;
use App\Models\RecipeIngredientLineMatch;
use App\Models\RecipeVersion;
use Brick\Math\BigDecimal;

final readonly class RecipeNutritionEstimator
{
    public const POLICY_VERSION = 1;

    public function __construct(private CalculationQuantityConverter $quantityConverter = new CalculationQuantityConverter) {}

    /** @return array<string, mixed> */
    public function estimate(Recipe $recipe): array
    {
        $recipe->loadMissing('ingredientLines.catalogueMatch.catalogueItemVersion.nutrientValues');

        /** @var array<string, BigDecimal> $totals */
        $totals = [];
        $inputs = [];

        foreach ($recipe->ingredientLines as $line) {
            $inputs[] = $this->estimateLine($line, $totals);
        }

        $servings = Decimal::parse((string) $recipe->servings);
        $wholeRecipe = [];
        $perServing = [];

        foreach (Nutrient::cases() as $nutrient) {
            $total = $totals[$nutrient->value] ?? null;

            if ($total === null) {
                continue;
            }

            $definition = NutrientRegistry::definition($nutrient);
            $wholeRecipe[$nutrient->value] = $this->outputValue($total, $definition->canonicalStorageUnit, NutrientBasis::PerRecipe);
            $perServing[$nutrient->value] = $this->outputValue(
                $total->dividedBy($servings, Decimal::DIVISION_GUARD_SCALE, Decimal::ROUNDING_MODE),
                $definition->canonicalStorageUnit,
                NutrientBasis::PerServing,
            );
        }

        return [
            'type' => 'estimate',
            'is_estimate' => true,
            'calculation_policy_version' => self::POLICY_VERSION,
            'whole_recipe' => $wholeRecipe,
            'per_serving' => $perServing,
            'inputs' => $inputs,
        ];
    }

    /**
     * Recalculate an immutable recipe version with an explicit dependency map.
     *
     * @param  array<int, string>  $catalogueVersionIdsByPosition
     * @return array<string, mixed>
     */
    public function estimateVersion(RecipeVersion $version, array $catalogueVersionIdsByPosition): array
    {
        $snapshot = $version->snapshot;
        $catalogueVersions = CatalogueItemVersion::query()
            ->with('nutrientValues')
            ->whereKey(array_values(array_unique($catalogueVersionIdsByPosition)))
            ->get()
            ->keyBy('id');
        $recipe = new Recipe;
        $recipe->forceFill(['servings' => $snapshot['servings'] ?? null]);
        $lines = collect($snapshot['ingredients'] ?? [])
            ->filter(fn (mixed $ingredient): bool => is_array($ingredient))
            ->map(function (array $ingredient) use ($catalogueVersionIdsByPosition, $catalogueVersions): RecipeIngredientLine {
                $position = (int) ($ingredient['position'] ?? 0);
                $line = new RecipeIngredientLine;
                $line->forceFill([
                    'position' => $position,
                    'quantity' => $ingredient['quantity'] ?? null,
                    'standard_unit' => $ingredient['standard_unit'] ?? null,
                    'custom_unit' => $ingredient['custom_unit'] ?? null,
                ]);
                $catalogueVersion = $catalogueVersions->get($catalogueVersionIdsByPosition[$position] ?? null);

                if ($catalogueVersion === null) {
                    $line->setRelation('catalogueMatch', null);

                    return $line;
                }

                $match = new RecipeIngredientLineMatch;
                $match->forceFill(['catalogue_item_version_id' => $catalogueVersion->getKey()]);
                $match->setRelation('catalogueItemVersion', $catalogueVersion);
                $line->setRelation('catalogueMatch', $match);

                return $line;
            })
            ->values();
        $recipe->setRelation('ingredientLines', $lines);

        return $this->estimate($recipe);
    }

    /**
     * @param  array<string, BigDecimal>  $totals
     * @return array<string, mixed>
     */
    private function estimateLine(RecipeIngredientLine $line, array &$totals): array
    {
        $input = [
            'ingredient_position' => $line->position,
            'quantity' => $line->quantity,
            'standard_unit' => $line->getRawOriginal('standard_unit'),
            'custom_unit' => $line->custom_unit,
            'catalogue_item_version_id' => $line->catalogueMatch?->catalogue_item_version_id,
            'contributions' => [],
            'exclusions' => [],
        ];

        if ($line->quantity === null) {
            $input['exclusions'][] = ['reason' => 'quantity_unavailable'];

            return $input;
        }

        $unit = $line->standard_unit;
        if ($unit === null && $line->custom_unit !== null) {
            $unit = new CustomUnit($line->custom_unit);
        }
        if ($unit === null) {
            $input['exclusions'][] = ['reason' => 'unit_unavailable'];

            return $input;
        }

        $version = $line->catalogueMatch?->catalogueItemVersion;
        if ($version === null) {
            $input['exclusions'][] = ['reason' => 'catalogue_match_unavailable'];

            return $input;
        }

        foreach (Nutrient::cases() as $nutrient) {
            $values = $version->nutrientValues
                ->where('nutrient', $nutrient)
                ->filter(fn (CatalogueNutrientValue $value): bool => $value->value !== null
                    && in_array($value->status, [NutrientValueStatus::Known, NutrientValueStatus::Approximate], true))
                ->values();

            if ($values->isEmpty()) {
                $input['exclusions'][] = ['nutrient' => $nutrient->value, 'reason' => 'nutrient_value_unavailable'];

                continue;
            }

            if ($values->count() !== 1) {
                $input['exclusions'][] = ['nutrient' => $nutrient->value, 'reason' => 'multiple_nutrient_bases'];

                continue;
            }

            /** @var CatalogueNutrientValue $value */
            $value = $values->sole();
            [$targetUnit, $divisor] = $this->basisConversion($value->basis);

            if ($targetUnit === null) {
                $input['exclusions'][] = ['nutrient' => $nutrient->value, 'reason' => 'unsupported_nutrient_basis'];

                continue;
            }

            $conversion = $this->quantityConverter->convert((string) $line->quantity, $unit, $targetUnit, $version);

            if ($conversion->isExcluded()) {
                $input['exclusions'][] = ['nutrient' => $nutrient->value, 'reason' => $conversion->exclusionReason?->value];

                continue;
            }

            $convertedQuantity = $conversion->convertedQuantity();
            $amount = Decimal::parse((string) $value->value)
                ->multipliedBy($convertedQuantity)
                ->dividedBy($divisor, Decimal::DIVISION_GUARD_SCALE, Decimal::ROUNDING_MODE);

            $totals[$nutrient->value] = isset($totals[$nutrient->value])
                ? $totals[$nutrient->value]->plus($amount)
                : $amount;

            $foodConversion = $conversion->foodConversion;
            $input['contributions'][] = [
                'nutrient' => $nutrient->value,
                'catalogue_nutrient_value_id' => (string) $value->getKey(),
                'catalogue_nutrient_basis' => $value->basis->value,
                'catalogue_nutrient_value' => (string) $value->value,
                'catalogue_nutrient_status' => $value->status->value,
                'normalization_policy_version' => $value->normalization_policy_version,
                'converted_quantity' => Decimal::forStorage($convertedQuantity),
                'converted_unit' => $targetUnit->value,
                'estimated_amount' => Decimal::forStorage($amount),
                'food_conversion' => $foodConversion === null ? null : [
                    'catalogue_item_version_id' => $foodConversion->catalogueItemVersionId,
                    'source' => $foodConversion->source->value,
                    'basis' => $foodConversion->basis,
                    'reliable' => $foodConversion->reliable,
                ],
            ];
        }

        return $input;
    }

    /** @return array{StandardUnit|null, string} */
    private function basisConversion(NutrientBasis $basis): array
    {
        return match ($basis) {
            NutrientBasis::Per100Gram => [StandardUnit::Gram, '100'],
            NutrientBasis::Per100Millilitre => [StandardUnit::Millilitre, '100'],
            NutrientBasis::PerServing => [StandardUnit::Serving, '1'],
            default => [null, '1'],
        };
    }

    /** @return array{value: string, unit: string, basis: string, status: string, is_estimate: true} */
    private function outputValue(BigDecimal $value, NutrientUnit $unit, NutrientBasis $basis): array
    {
        return [
            'value' => Decimal::forStorage($value),
            'unit' => $unit->value,
            'basis' => $basis->value,
            'status' => NutrientValueStatus::Approximate->value,
            'is_estimate' => true,
        ];
    }
}
