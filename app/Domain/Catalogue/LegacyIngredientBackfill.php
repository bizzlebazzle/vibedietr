<?php

namespace App\Domain\Catalogue;

use App\Models\LegacyIngredientCatalogueMapping;
use Illuminate\Support\Facades\DB;

final class LegacyIngredientBackfill
{
    public const VERSION = 1;

    /** @var list<string> */
    private const SNAPSHOT_FIELDS = [
        'name',
        'barcode',
        'barcode_provenance',
        'barcode_source',
        'barcode_imported_at',
        'keywords',
        'categories',
        'nutriments',
        'quantity',
        'quantity_unit',
        'serving_quantity',
        'serving_quantity_unit',
        'recommended_servings',
        'image_url',
        'created_at',
        'updated_at',
    ];

    public function persist(
        object $ingredient,
        LegacyIngredientClassificationResult $result,
    ): LegacyIngredientCatalogueMapping {
        return DB::transaction(function () use ($ingredient, $result): LegacyIngredientCatalogueMapping {
            $existing = LegacyIngredientCatalogueMapping::query()
                ->where('ingredient_id', $ingredient->id)
                ->lockForUpdate()
                ->first();

            $checksum = $this->checksum($ingredient);

            if ($existing !== null) {
                if (! hash_equals($existing->legacy_checksum, $checksum)) {
                    throw new LegacyIngredientSourceChanged((int) $ingredient->id);
                }

                return $existing;
            }

            $now = now()->utc();
            $catalogueItemId = null;

            if ($result->canCreateCandidate()) {
                $verified = $result->classification === LegacyIngredientClassification::VerifiedImported;
                $catalogueItemId = DB::table('catalogue_items')->insertGetId([
                    'origin' => $verified
                        ? CatalogueItemOrigin::Barcode->value
                        : CatalogueItemOrigin::Manual->value,
                    'barcode' => $verified ? (string) $ingredient->barcode : null,
                    'submitted_by_user_id' => $ingredient->user_id,
                    'source' => $verified
                        ? CatalogueItemSource::OpenFoodFacts->value
                        : CatalogueItemSource::Manual->value,
                    'source_identifier' => $verified ? (string) $ingredient->barcode : null,
                    'introduced_at' => ($verified
                        ? $ingredient->barcode_imported_at
                        : $ingredient->created_at)
                        ?? $ingredient->created_at
                        ?? $now,
                    'status' => CatalogueItemStatus::Pending->value,
                    'current_catalogue_item_version_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $mapping = new LegacyIngredientCatalogueMapping;
            $mapping->forceFill([
                'ingredient_id' => $ingredient->id,
                'legacy_user_id' => $ingredient->user_id,
                'catalogue_item_id' => $catalogueItemId,
                'classification' => $result->classification,
                'review_reason' => $result->reviewReason,
                'legacy_snapshot' => $this->snapshot($ingredient),
                'legacy_checksum' => $checksum,
                'backfill_version' => self::VERSION,
                'backfilled_at' => $now,
            ]);
            $mapping->save();

            return $mapping;
        }, 3);
    }

    /** @return array<string, mixed> */
    public function snapshot(object $ingredient): array
    {
        $snapshot = [];

        foreach (self::SNAPSHOT_FIELDS as $field) {
            $snapshot[$field] = $ingredient->{$field};
        }

        return $snapshot;
    }

    public function checksum(object $ingredient): string
    {
        $identityAndOwner = [
            'id' => $ingredient->id,
            'user_id' => $ingredient->user_id,
        ];

        return hash('sha256', json_encode(
            $identityAndOwner + $this->snapshot($ingredient),
            JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));
    }
}
