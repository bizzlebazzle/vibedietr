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
        $servingQuantity = $this->optionalNumber($product, 'serving_quantity');

        return new OpenFoodFactsProductData(
            code: $code,
            name: $this->optionalString($product, 'product_name'),
            keywords: $this->stringList($product, 'keywords', '_keywords'),
            categories: $this->stringList($product, 'categories_tags'),
            quantity: $quantity['quantity'],
            quantityUnit: $quantity['unit'],
            multipleQuantity: $quantity['multiple'],
            servingQuantity: $servingQuantity,
            servingQuantityUnit: $this->unitFromText($this->optionalString($product, 'serving_size')),
            imageUrl: $this->optionalString($product, 'image_front_url')
                ?? $this->optionalString($product, 'image_front_small_url'),
            nutriments: $this->mapNutriments($rawNutriments),
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
    private function optionalNumber(array $product, string $key): int|float|null
    {
        if (! array_key_exists($key, $product) || $product[$key] === null || $product[$key] === '') {
            return null;
        }

        $value = $product[$key];

        if ((! is_int($value) && ! is_float($value) && ! is_string($value)) || ! is_numeric($value)) {
            throw new InvalidOpenFoodFactsResponse;
        }

        return $this->normalizeNumber((string) $value);
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
