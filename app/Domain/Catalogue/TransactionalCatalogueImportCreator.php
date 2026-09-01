<?php

namespace App\Domain\Catalogue;

use App\Domain\Nutrition\CatalogueNutritionNormalizer;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

final readonly class TransactionalCatalogueImportCreator implements CatalogueImportCreator
{
    public function __construct(private CatalogueNutritionNormalizer $nutrition) {}

    public function createOrReuse(
        User $submitter,
        string $barcode,
        CatalogueImportData $mapped,
    ): CatalogueBarcodeImportResult {
        return DB::transaction(function () use ($submitter, $barcode, $mapped): CatalogueBarcodeImportResult {
            $existing = CatalogueItem::query()
                ->where('barcode', $barcode)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $status = $existing->status === CatalogueItemStatus::Approved
                    ? CatalogueBarcodeImportStatus::Reused
                    : CatalogueBarcodeImportStatus::Unavailable;

                return new CatalogueBarcodeImportResult(
                    $status,
                    $status === CatalogueBarcodeImportStatus::Reused ? $existing : null,
                );
            }

            $now = Date::now()->toImmutable()->utc();
            $item = new CatalogueItem;
            $item->forceFill([
                'origin' => CatalogueItemOrigin::Barcode,
                'barcode' => $barcode,
                'submitted_by_user_id' => $submitter->getKey(),
                'source' => CatalogueItemSource::OpenFoodFacts,
                'source_identifier' => $barcode,
                'introduced_at' => $now,
                'status' => CatalogueItemStatus::Approved,
                'current_catalogue_item_version_id' => null,
            ]);
            $item->save();

            $structure = $mapped->package;
            $hasPackage = $structure->packageCount !== null
                || $structure->itemType !== null
                || $structure->amountPerItem !== null
                || $structure->servingsPerItem !== null;
            $hasServing = $structure->servingAmount !== null;
            $version = new CatalogueItemVersion;
            $version->forceFill([
                'catalogue_item_id' => $item->getKey(),
                'version_number' => 1,
                'name' => $mapped->name,
                'keywords' => $mapped->keywords === [] ? null : $mapped->keywords,
                'categories' => $mapped->categories === [] ? null : $mapped->categories,
                'image_url' => $mapped->imageUrl,
                'name_source' => $mapped->name === null ? null : CatalogueItemSource::OpenFoodFacts,
                'keywords_source' => $mapped->keywords === [] ? null : CatalogueItemSource::OpenFoodFacts,
                'categories_source' => $mapped->categories === [] ? null : CatalogueItemSource::OpenFoodFacts,
                'package_source' => $hasPackage ? CatalogueItemSource::OpenFoodFacts : null,
                'serving_source' => $hasServing ? CatalogueItemSource::OpenFoodFacts : null,
                'image_source' => $mapped->imageUrl === null ? null : CatalogueItemSource::OpenFoodFacts,
                ...$structure->toAttributes(),
            ]);
            $version->save();

            $this->nutrition->store($version, $mapped->nutrition);
            $item->setCurrentVersion($version);

            return new CatalogueBarcodeImportResult(
                CatalogueBarcodeImportStatus::Created,
                $item->fresh(['currentVersion.nutrientValues.sourceObservation']),
            );
        }, 3);
    }
}
