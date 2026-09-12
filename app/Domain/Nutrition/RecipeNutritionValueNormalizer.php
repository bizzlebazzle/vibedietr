<?php

namespace App\Domain\Nutrition;

use App\Domain\Shared\Decimal;
use InvalidArgumentException;

final readonly class RecipeNutritionValueNormalizer
{
    public const POLICY_VERSION = 1;

    public function __construct(private NutrientUnitConverter $converter = new NutrientUnitConverter) {}

    /**
     * Values use display units: kcal, kJ, grams, and milligrams for sodium.
     *
     * @param  array<string, string|int|null>  $values
     * @return array<string, array<string, mixed>>
     */
    public function normalize(array $values, string $provenance): array
    {
        $unknown = array_diff(array_keys($values), NutrientRegistry::stableIdentifiers());
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown recipe nutrient: '.reset($unknown).'.');
        }

        $normalized = [];
        $kcal = $this->decimal($values[Nutrient::EnergyKcal->value] ?? null);
        $kj = $this->decimal($values[Nutrient::EnergyKj->value] ?? null);
        if ($kcal !== null || $kj !== null) {
            if ($kcal !== null) {
                $canonicalKcal = $kcal;
                $normalized[Nutrient::EnergyKcal->value] = $this->fact(
                    $canonicalKcal, Nutrient::EnergyKcal, $provenance,
                    sourceValue: $kcal, sourceUnit: NutrientUnit::Kilocalorie,
                );
                $normalized[Nutrient::EnergyKj->value] = $this->fact(
                    $canonicalKcal, Nutrient::EnergyKj, 'derived', 'energy_kj_from_kcal',
                    sourceValue: $kj, sourceUnit: $kj === null ? null : NutrientUnit::Kilojoule,
                );
            } else {
                $canonicalKcal = Decimal::forStorage($this->converter->convert(
                    $kj, NutrientUnit::Kilojoule, NutrientUnit::Kilocalorie,
                ));
                $normalized[Nutrient::EnergyKcal->value] = $this->fact(
                    $canonicalKcal, Nutrient::EnergyKcal, 'derived', 'energy_kcal_from_kj',
                );
                $normalized[Nutrient::EnergyKj->value] = $this->fact(
                    $canonicalKcal, Nutrient::EnergyKj, $provenance,
                    sourceValue: $kj, sourceUnit: NutrientUnit::Kilojoule,
                );
            }
        }

        foreach (Nutrient::cases() as $nutrient) {
            if (in_array($nutrient, [Nutrient::EnergyKcal, Nutrient::EnergyKj], true)) {
                continue;
            }
            $value = $this->decimal($values[$nutrient->value] ?? null);
            if ($value === null) {
                continue;
            }
            $sourceValue = $value;
            $sourceUnit = $nutrient === Nutrient::Sodium ? NutrientUnit::Milligram : NutrientUnit::Gram;
            if ($nutrient === Nutrient::Sodium) {
                $value = Decimal::forStorage($this->converter->convert(
                    $value,
                    $sourceUnit,
                    NutrientUnit::Gram,
                ));
            }
            $normalized[$nutrient->value] = $this->fact(
                $value, $nutrient, $provenance, sourceValue: $sourceValue, sourceUnit: $sourceUnit,
            );
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('At least one recipe nutrient value is required.');
        }

        return $normalized;
    }

    private function decimal(string|int|null $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return Decimal::forStorage(Decimal::parse($value));
    }

    /** @return array<string, mixed> */
    private function fact(
        string $value,
        Nutrient $nutrient,
        string $provenance,
        ?string $derivation = null,
        ?string $sourceValue = null,
        ?NutrientUnit $sourceUnit = null,
    ): array {
        return [
            'value' => $value,
            'unit' => NutrientRegistry::definition($nutrient)->canonicalStorageUnit->value,
            'basis' => NutrientBasis::PerServing->value,
            'status' => NutrientValueStatus::Known->value,
            'is_estimate' => false,
            'provenance' => $provenance,
            'normalization_policy_version' => self::POLICY_VERSION,
            'derivation' => $derivation,
            'source_value' => $sourceValue,
            'source_unit' => $sourceUnit?->value,
        ];
    }
}
