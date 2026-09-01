<?php

namespace App\Domain\Catalogue;

use App\Models\CatalogueItem;
use JsonException;

final readonly class CatalogueItemReadModel
{
    /**
     * @param  list<string>  $keywords
     * @param  list<string>  $categories
     * @param  array<string, mixed>  $nutriments
     * @param  list<CatalogueNutrientReadModel>  $nutritionFacts
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $barcode,
        public ?string $quantity,
        public ?string $quantityUnit,
        public ?string $servingQuantity,
        public ?string $servingQuantityUnit,
        public ?string $recommendedServings,
        public ?string $imageUrl,
        public array $keywords,
        public array $categories,
        public array $nutriments,
        public array $nutritionFacts,
        public bool $pending,
    ) {}

    public static function fromCatalogueItem(CatalogueItem $item): self
    {
        $snapshot = self::snapshot($item->getAttribute('migration_snapshot'));
        $nutritionFacts = [];

        if ($item->relationLoaded('currentVersion')
            && $item->currentVersion?->relationLoaded('nutrientValues')) {
            $nutritionFacts = $item->currentVersion->nutrientValues
                ->map(static fn ($value): CatalogueNutrientReadModel => CatalogueNutrientReadModel::fromValue($value))
                ->values()
                ->all();
        }

        return new self(
            id: (int) $item->getKey(),
            name: trim((string) ($snapshot['name'] ?? '')) ?: 'Unnamed catalogue item',
            barcode: $item->barcode,
            quantity: self::nullableString($snapshot['quantity'] ?? null),
            quantityUnit: self::nullableString($snapshot['quantity_unit'] ?? null),
            servingQuantity: self::nullableString($snapshot['serving_quantity'] ?? null),
            servingQuantityUnit: self::nullableString($snapshot['serving_quantity_unit'] ?? null),
            recommendedServings: self::nullableString($snapshot['recommended_servings'] ?? null),
            imageUrl: self::nullableString($snapshot['image_url'] ?? null),
            keywords: self::stringList($snapshot['keywords'] ?? null),
            categories: self::stringList($snapshot['categories'] ?? null),
            nutriments: is_array($snapshot['nutriments'] ?? null) ? $snapshot['nutriments'] : [],
            nutritionFacts: $nutritionFacts,
            pending: $item->status === CatalogueItemStatus::Pending,
        );
    }

    /** @return array<string, mixed> */
    private static function snapshot(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return [];
        }

        try {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $entry): ?string => self::nullableString($entry), $value),
        ));
    }
}
