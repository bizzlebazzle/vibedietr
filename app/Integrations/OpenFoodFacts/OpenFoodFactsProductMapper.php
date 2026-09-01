<?php

namespace App\Integrations\OpenFoodFacts;

use App\Domain\Measurements\MeasurementUnitParser;
use App\Domain\Nutrition\NutrientRegistry;
use App\Domain\Shared\Decimal;
use Throwable;

final class OpenFoodFactsProductMapper
{
    /**
     * @param  array<string, mixed>  $response
     */
    public function map(array $response): OpenFoodFactsProductData
    {
        if (($response['status'] ?? null) !== 'success'
            || data_get($response, 'result.id') !== 'product_found'
            || ! isset($response['product'])
            || ! is_array($response['product'])
        ) {
            throw new InvalidOpenFoodFactsResponse;
        }

        $product = $response['product'];
        $code = $this->requiredString($product, 'code');
        $rawNutriments = $product['nutriments'] ?? null;

        if (! is_array($rawNutriments)) {
            throw new InvalidOpenFoodFactsResponse;
        }

        $quantity = $this->parseQuantity($this->optionalString($product, 'quantity'));
        $servingQuantitySource = $this->optionalNumberString($product, 'serving_quantity');
        $servingQuantity = $servingQuantitySource === null ? null : $this->normalizeNumber($servingQuantitySource);
        $servingUnit = $this->unitFromText($this->optionalString($product, 'serving_size'));
        $package = $this->mapPackage($this->optionalString($product, 'quantity'), $servingQuantitySource, $servingUnit);

        return new OpenFoodFactsProductData(
            code: $code,
            name: $this->optionalString($product, 'product_name'),
            keywords: $this->stringList($product, 'keywords', '_keywords'),
            categories: $this->stringList($product, 'categories_tags'),
            quantity: $quantity['quantity'],
            quantityUnit: $quantity['unit'],
            multipleQuantity: $quantity['multiple'],
            servingQuantity: $servingQuantity,
            servingQuantityUnit: $servingUnit,
            imageUrl: $this->optionalString($product, 'image_front_url')
                ?? $this->optionalString($product, 'image_front_small_url'),
            nutriments: $this->mapNutriments($rawNutriments),
            package: $package,
            nutrientData: $this->mapNutrientData($rawNutriments),
        );
    }

    /**
     * @param  array<string, mixed>  $nutriments
     * @return array<string, mixed>
     */
    private function mapNutriments(array $nutriments): array
    {
        $mapped = ['raw' => $nutriments];

        foreach ($nutriments as $providerKey => $value) {
            if (preg_match('/^(.+)_(100g|serving)$/', $providerKey, $matches) !== 1) {
                continue;
            }

            $definition = NutrientRegistry::find($matches[1]);

            if ($definition === null || $value === null) {
                continue;
            }

            if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
                throw new InvalidOpenFoodFactsResponse;
            }

            try {
                $decimal = Decimal::parse(trim((string) $value));
            } catch (Throwable) {
                throw new InvalidOpenFoodFactsResponse;
            }

            if ($decimal->isNegative()) {
                throw new InvalidOpenFoodFactsResponse;
            }

            $bucket = $matches[2] === '100g' ? 'per_100g' : 'per_serving';
            $mapped[$bucket][$definition->id->value] = $value;
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $nutriments
     * @return list<OpenFoodFactsNutrientData>
     */
    private function mapNutrientData(array $nutriments): array
    {
        $mapped = [];

        foreach ($nutriments as $providerKey => $value) {
            if (preg_match('/^(.+)_(100g|serving)$/', $providerKey, $matches) !== 1) {
                continue;
            }

            $definition = NutrientRegistry::find($matches[1]);

            if ($definition === null || $value === null) {
                continue;
            }

            if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
                throw new InvalidOpenFoodFactsResponse;
            }

            $lexical = trim((string) $value);

            try {
                $decimal = Decimal::parse($lexical);
            } catch (Throwable) {
                throw new InvalidOpenFoodFactsResponse;
            }

            if ($decimal->isNegative()) {
                throw new InvalidOpenFoodFactsResponse;
            }

            $unit = $this->nutrientUnit(
                $nutriments[$matches[1].'_unit'] ?? null,
                $definition->id->value,
            );

            if ($unit === null) {
                continue;
            }

            $mapped[] = new OpenFoodFactsNutrientData(
                nutrient: $definition->id->value,
                basis: $matches[2] === '100g' ? 'per_100g' : 'per_serving',
                value: $lexical,
                unit: $unit,
                sourceField: $providerKey,
            );
        }

        return $mapped;
    }

    private function nutrientUnit(mixed $providerUnit, string $nutrient): ?string
    {
        if ($providerUnit !== null && ! is_string($providerUnit)) {
            return null;
        }

        $unit = strtolower(trim((string) ($providerUnit ?? '')));

        if ($unit === '') {
            return match ($nutrient) {
                'energy_kcal' => 'kcal',
                'energy_kj' => 'kj',
                default => 'g',
            };
        }

        $unit = match ($unit) {
            'kilocalorie', 'kilocalories' => 'kcal',
            'kilojoule', 'kilojoules' => 'kj',
            'gram', 'grams' => 'g',
            'milligram', 'milligrams' => 'mg',
            default => $unit,
        };

        return in_array($unit, ['kcal', 'kj', 'g', 'mg'], true) ? $unit : null;
    }

    /**
     * @param  array<string, mixed>  $product
     * @return list<string>
     */
    private function stringList(array $product, string $key, ?string $fallback = null): array
    {
        $value = $product[$key] ?? ($fallback === null ? [] : ($product[$fallback] ?? []));

        if (! is_array($value)) {
            throw new InvalidOpenFoodFactsResponse;
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidOpenFoodFactsResponse;
            }
        }

        return array_values(array_unique($value));
    }

    /** @param array<string, mixed> $product */
    private function requiredString(array $product, string $key): string
    {
        $value = $this->optionalString($product, $key);

        if ($value === null || $value === '') {
            throw new InvalidOpenFoodFactsResponse;
        }

        return $value;
    }

    /** @param array<string, mixed> $product */
    private function optionalString(array $product, string $key): ?string
    {
        if (! array_key_exists($key, $product) || $product[$key] === null) {
            return null;
        }

        if (! is_string($product[$key])) {
            throw new InvalidOpenFoodFactsResponse;
        }

        return trim($product[$key]);
    }

    /** @param array<string, mixed> $product */
    private function optionalNumberString(array $product, string $key): ?string
    {
        if (! array_key_exists($key, $product) || $product[$key] === null || $product[$key] === '') {
            return null;
        }

        $value = $product[$key];

        if ((! is_int($value) && ! is_float($value) && ! is_string($value)) || ! is_numeric($value)) {
            throw new InvalidOpenFoodFactsResponse;
        }

        return trim((string) $value);
    }

    /**
     * @return array{quantity: int|float|null, unit: string|null, multiple: bool}
     */
    private function parseQuantity(?string $quantityText): array
    {
        if ($quantityText === null || $quantityText === '') {
            return ['quantity' => null, 'unit' => null, 'multiple' => false];
        }

        $value = mb_strtolower($quantityText);

        if (preg_match('/(\d+(?:[.,]\d+)?)\s*[x×]\s*(\d+(?:[.,]\d+)?)\s*[a-z]/i', $value, $matches) === 1
            || preg_match('/\((\d+(?:[.,]\d+)?)\s*[x×]\s*(\d+(?:[.,]\d+)?)\s*[a-z][^)]*\)/i', $value, $matches) === 1
        ) {
            return [
                'quantity' => $this->normalizeNumber($matches[1]),
                'unit' => null,
                'multiple' => true,
            ];
        }

        if (preg_match('/\b(\d+(?:[.,]\d+)?)\b/', $value, $matches) === 1) {
            return [
                'quantity' => $this->normalizeNumber($matches[1]),
                'unit' => $this->unitFromText($quantityText),
                'multiple' => false,
            ];
        }

        return [
            'quantity' => null,
            'unit' => $this->unitFromText($quantityText),
            'multiple' => false,
        ];
    }

    private function mapPackage(
        ?string $quantityText,
        ?string $servingQuantity,
        ?string $servingUnit,
    ): OpenFoodFactsPackageData {
        $packageCount = null;
        $itemType = null;
        $amount = null;
        $amountUnit = null;

        if ($quantityText !== null) {
            $text = mb_strtolower(trim($quantityText));
            $number = '(\\d+(?:[.,]\\d+)?)';
            $unit = '([a-z]+)';
            $item = '([a-z]+)?';
            $multipack = '/(?:^|\\()\\s*(\\d+)\\s*'.$item.'\\s*[x\\x{00D7}]\\s*'.$number.'\\s*'.$unit.'/iu';

            if (preg_match($multipack, $text, $matches) === 1) {
                $candidateUnit = $this->unitFromText($matches[4]);
                $candidateAmount = $this->decimalString($matches[3]);
                $candidateCount = (int) $matches[1];

                if ($candidateUnit !== null && $candidateAmount !== null && $candidateCount > 0) {
                    $packageCount = $candidateCount;
                    $itemType = $this->singularItemType($matches[2]);
                    $amount = $candidateAmount;
                    $amountUnit = $candidateUnit;
                }
            } elseif (preg_match('/^\\s*'.$number.'\\s*'.$unit.'\\s*$/iu', $text, $matches) === 1) {
                $candidateUnit = $this->unitFromText($matches[2]);
                $candidateAmount = $this->decimalString($matches[1]);

                if ($candidateUnit !== null && $candidateAmount !== null) {
                    $packageCount = 1;
                    $amount = $candidateAmount;
                    $amountUnit = $candidateUnit;
                }
            } elseif (preg_match('/^\\s*(\\d+)\\s+([a-z]+)\\s*$/iu', $text, $matches) === 1) {
                $candidateCount = (int) $matches[1];

                if ($candidateCount > 0) {
                    $packageCount = $candidateCount;
                    $itemType = $this->singularItemType($matches[2]);
                }
            }
        }

        $directServing = $servingQuantity !== null && $servingUnit !== null
            ? $this->decimalString($servingQuantity)
            : null;

        return new OpenFoodFactsPackageData(
            packageCount: $packageCount,
            itemType: $itemType,
            amountPerItem: $amount,
            amountPerItemUnit: $amountUnit,
            servingsPerItem: null,
            servingAmount: $directServing,
            servingAmountUnit: $directServing === null ? null : $servingUnit,
        );
    }

    private function decimalString(string $value): ?string
    {
        $value = str_replace(',', '.', trim($value));

        try {
            $decimal = Decimal::parse($value);
        } catch (Throwable) {
            return null;
        }

        return $decimal->isPositive() ? $value : null;
    }

    private function singularItemType(?string $itemType): ?string
    {
        $itemType = trim((string) $itemType);

        if ($itemType === '') {
            return null;
        }

        $itemType = match ($itemType) {
            'cans' => 'can',
            'bottles' => 'bottle',
            'bars' => 'bar',
            'sachets' => 'sachet',
            'packs' => 'pack',
            'portions' => 'portion',
            'loaves' => 'loaf',
            default => $itemType,
        };

        return mb_strlen($itemType) <= 32 ? $itemType : null;
    }

    private function unitFromText(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        $unit = MeasurementUnitParser::findInText($text);

        return $unit === null ? null : MeasurementUnitParser::parsedValue($unit);
    }

    private function normalizeNumber(string $value): int|float|null
    {
        $normalized = str_replace(',', '.', trim($value));

        if (! is_numeric($normalized)) {
            return null;
        }

        $number = (float) $normalized;

        return floor($number) === $number ? (int) $number : $number;
    }
}
