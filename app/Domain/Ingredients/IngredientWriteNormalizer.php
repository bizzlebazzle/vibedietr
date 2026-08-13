<?php

namespace App\Domain\Ingredients;

use App\Domain\Measurements\MeasurementUnitParser;
use App\Domain\Nutrition\EnergyNormalizer;
use App\Domain\Shared\Decimal;

final readonly class IngredientWriteNormalizer
{
    public function __construct(private EnergyNormalizer $energyNormalizer = new EnergyNormalizer) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function normalize(array $validated): array
    {
        foreach (['name', 'barcode', 'image_url'] as $field) {
            if (array_key_exists($field, $validated) && is_string($validated[$field])) {
                $validated[$field] = trim($validated[$field]);
            }
        }

        foreach (['barcode', 'image_url'] as $field) {
            if (array_key_exists($field, $validated) && $this->isBlank($validated[$field])) {
                $validated[$field] = null;
            }
        }

        foreach (['keywords', 'categories'] as $field) {
            if (array_key_exists($field, $validated) && $validated[$field] === []) {
                $validated[$field] = null;
            }
        }

        $validated['quantity_unit'] = MeasurementUnitParser::storageValue($validated['quantity_unit']);

        if (array_key_exists('serving_quantity_unit', $validated)) {
            $validated['serving_quantity_unit'] = $this->isBlank($validated['serving_quantity_unit'])
                ? null
                : MeasurementUnitParser::storageValue($validated['serving_quantity_unit']);
        }

        foreach (['serving_quantity', 'recommended_servings'] as $field) {
            if (array_key_exists($field, $validated) && $this->isBlank($validated[$field])) {
                $validated[$field] = null;
            }
        }

        if (array_key_exists('nutriments', $validated)) {
            $validated['nutriments'] = $this->normalizeNutriments($validated['nutriments']);
        }

        return $validated;
    }

    /** @param array<string, mixed>|null $nutriments */
    private function normalizeNutriments(?array $nutriments): ?array
    {
        if ($nutriments === null || $nutriments === []) {
            return null;
        }

        $keyMap = IngredientWriteContract::nutrientKeyMap();

        foreach (['per_100g', 'per_serving'] as $bucket) {
            if (! isset($nutriments[$bucket]) || ! is_array($nutriments[$bucket])) {
                continue;
            }

            $normalized = [];

            foreach ($nutriments[$bucket] as $key => $value) {
                if ($this->isBlank($value)) {
                    continue;
                }

                $canonicalKey = $keyMap[$key]->value;
                $decimal = Decimal::parse($this->decimalString($value));
                $normalized[$canonicalKey] = $decimal->isZero()
                    ? 0
                    : Decimal::forStorage($decimal);
            }

            $normalized = $this->energyNormalizer->normalize($normalized);

            if ($normalized === []) {
                unset($nutriments[$bucket]);
            } else {
                $nutriments[$bucket] = $normalized;
            }
        }

        return $nutriments === [] ? null : $nutriments;
    }

    private function decimalString(mixed $value): string
    {
        return is_string($value) ? trim($value) : (string) $value;
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
