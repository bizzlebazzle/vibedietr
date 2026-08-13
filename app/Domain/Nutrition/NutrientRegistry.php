<?php

namespace App\Domain\Nutrition;

use App\Domain\Shared\Decimal;
use InvalidArgumentException;

final class NutrientRegistry
{
    /** @return list<NutrientDefinition> */
    public static function all(): array
    {
        return [
            self::definitionFor(Nutrient::EnergyKcal, 'Energy', NutrientUnit::Kilocalorie, NutrientUnit::Kilocalorie, 0, true, false, null, null, ['energy-kcal', 'energy kcal', 'calories', 'kcal']),
            self::definitionFor(Nutrient::EnergyKj, 'Energy', NutrientUnit::Kilocalorie, NutrientUnit::Kilojoule, 0, false, true, Nutrient::EnergyKcal, EnergyConversion::KILOJOULES_PER_KILOCALORIE, ['energy-kj', 'energy kj', 'kilojoules', 'kj']),
            self::definitionFor(Nutrient::Fat, 'Fat', NutrientUnit::Gram, NutrientUnit::Gram, 1, aliases: ['fat']),
            self::definitionFor(Nutrient::SaturatedFat, 'Saturated fat', NutrientUnit::Gram, NutrientUnit::Gram, 1, aliases: ['saturated-fat', 'saturated fat', 'saturates']),
            self::definitionFor(Nutrient::Carbohydrates, 'Carbohydrate', NutrientUnit::Gram, NutrientUnit::Gram, 1, aliases: ['carbohydrate', 'carbohydrates', 'carbs']),
            self::definitionFor(Nutrient::Sugars, 'Sugars', NutrientUnit::Gram, NutrientUnit::Gram, 1, aliases: ['sugar', 'sugars']),
            self::definitionFor(Nutrient::Fibre, 'Fibre', NutrientUnit::Gram, NutrientUnit::Gram, 1, aliases: ['fibre', 'fiber']),
            self::definitionFor(Nutrient::Protein, 'Protein', NutrientUnit::Gram, NutrientUnit::Gram, 1, aliases: ['protein', 'proteins']),
            self::definitionFor(Nutrient::Salt, 'Salt', NutrientUnit::Gram, NutrientUnit::Gram, 2, aliases: ['salt']),
            self::definitionFor(Nutrient::Sodium, 'Sodium', NutrientUnit::Gram, NutrientUnit::Milligram, 0, aliases: ['sodium']),
        ];
    }

    public static function definition(Nutrient $nutrient): NutrientDefinition
    {
        foreach (self::all() as $definition) {
            if ($definition->id === $nutrient) {
                return $definition;
            }
        }

        throw new InvalidArgumentException("Unknown nutrient: {$nutrient->value}");
    }

    public static function find(string $identifierOrAlias): ?NutrientDefinition
    {
        $normalized = self::normalizeAlias($identifierOrAlias);

        foreach (self::all() as $definition) {
            if (self::normalizeAlias($definition->id->value) === $normalized) {
                return $definition;
            }

            foreach ($definition->aliases as $alias) {
                if (self::normalizeAlias($alias) === $normalized) {
                    return $definition;
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function stableIdentifiers(): array
    {
        return array_map(
            static fn (NutrientDefinition $definition): string => $definition->id->value,
            self::all(),
        );
    }

    /**
     * @param  list<string>  $aliases
     */
    private static function definitionFor(
        Nutrient $nutrient,
        string $label,
        NutrientUnit $canonicalUnit,
        NutrientUnit $displayUnit,
        int $displayPrecision,
        bool $authoritative = false,
        bool $derived = false,
        ?Nutrient $derivedFrom = null,
        ?string $derivationFactor = null,
        array $aliases = [],
    ): NutrientDefinition {
        return new NutrientDefinition(
            id: $nutrient,
            label: $label,
            canonicalStorageUnit: $canonicalUnit,
            preferredDisplayUnit: $displayUnit,
            supportedBases: NutrientBasis::cases(),
            storagePrecision: Decimal::STORAGE_PRECISION,
            storageScale: Decimal::STORAGE_SCALE,
            calculationPrecision: Decimal::CALCULATION_PRECISION,
            divisionGuardScale: Decimal::DIVISION_GUARD_SCALE,
            displayPrecision: $displayPrecision,
            displayRounding: Decimal::ROUNDING_MODE,
            authoritative: $authoritative,
            derived: $derived,
            derivedFrom: $derivedFrom,
            derivationFactor: $derivationFactor,
            aliases: $aliases,
        );
    }

    private static function normalizeAlias(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return preg_replace('/[\s_-]+/u', ' ', $value) ?? $value;
    }
}
