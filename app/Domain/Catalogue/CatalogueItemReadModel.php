<?php

namespace App\Domain\Catalogue;

use App\Domain\Measurements\MeasurementUnitRegistry;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
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
        /** @var CatalogueItemVersion|null $version */
        $version = $item->relationLoaded('currentVersion') ? $item->getRelation('currentVersion') : null;
        $nutritionFacts = [];

        if ($item->relationLoaded('currentVersion')
            && $item->currentVersion?->relationLoaded('nutrientValues')) {
            $nutritionFacts = $item->currentVersion->nutrientValues
                ->map(static fn ($value): CatalogueNutrientReadModel => CatalogueNutrientReadModel::fromValue($value))
                ->values()
                ->all();
        }

        $name = $snapshot['name'] ?? null;
        $quantity = $snapshot['quantity'] ?? null;
        $quantityUnit = $snapshot['quantity_unit'] ?? null;
        $servingQuantity = $snapshot['serving_quantity'] ?? null;
        $servingQuantityUnit = $snapshot['serving_quantity_unit'] ?? null;
        $recommendedServings = $snapshot['recommended_servings'] ?? null;
        $imageUrl = $snapshot['image_url'] ?? null;
        $keywords = $snapshot['keywords'] ?? null;
        $categories = $snapshot['categories'] ?? null;

        if ($version !== null) {
            $name = $version->name ?? $name;
            $quantity = $version->amount_per_item ?? $quantity;
            $quantityUnit = $version->amount_per_item_unit === null
                ? $quantityUnit
                : MeasurementUnitRegistry::definition($version->amount_per_item_unit)->symbol;
            $servingQuantity = $version->serving_amount ?? $servingQuantity;
            $servingQuantityUnit = $version->serving_amount_unit === null
                ? $servingQuantityUnit
                : MeasurementUnitRegistry::definition($version->serving_amount_unit)->symbol;
            $recommendedServings = $version->servings_per_item ?? $recommendedServings;
            $imageUrl = $version->image_url ?? $imageUrl;
            $keywords = $version->keywords ?? $keywords;
            $categories = $version->categories ?? $categories;
        }

        return new self(
            id: (int) $item->getKey(),
            name: trim((string) $name) ?: 'Unnamed catalogue item',
            barcode: $item->barcode,
            quantity: self::nullableString($quantity),
            quantityUnit: self::nullableString($quantityUnit),
            servingQuantity: self::nullableString($servingQuantity),
            servingQuantityUnit: self::nullableString($servingQuantityUnit),
            recommendedServings: self::nullableString($recommendedServings),
            imageUrl: self::nullableString($imageUrl),
            keywords: self::stringList($keywords),
            categories: self::stringList($categories),
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
