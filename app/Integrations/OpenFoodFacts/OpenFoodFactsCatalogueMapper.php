<?php

namespace App\Integrations\OpenFoodFacts;

use App\Domain\Catalogue\CatalogueImportData;
use App\Domain\Catalogue\CatalogueItemSource;
use App\Domain\Catalogue\PackageStructure;
use App\Domain\Nutrition\CatalogueNutrientObservation;
use App\Domain\Nutrition\Nutrient;
use App\Domain\Nutrition\NutrientBasis;
use App\Domain\Nutrition\NutrientProvenance;
use App\Domain\Nutrition\NutrientUnit;
use Illuminate\Support\Facades\Date;

final class OpenFoodFactsCatalogueMapper
{
    public function map(OpenFoodFactsProductData $product): CatalogueImportData
    {
        $package = $product->package;
        $importedAt = Date::now()->toImmutable()->utc();

        return new CatalogueImportData(
            name: $this->safeText($product->name, 255),
            keywords: $this->safeList($product->keywords),
            categories: $this->safeList($product->categories),
            imageUrl: $this->safeImageUrl($product->imageUrl),
            package: PackageStructure::make(
                packageCount: $package->packageCount,
                itemType: $package->itemType,
                amountPerItem: $package->amountPerItem,
                amountPerItemUnit: $package->amountPerItemUnit,
                servingsPerItem: $package->servingsPerItem,
                servingAmount: $package->servingAmount,
                servingAmountUnit: $package->servingAmountUnit,
            ),
            nutrition: array_map(
                static fn (OpenFoodFactsNutrientData $nutrient): CatalogueNutrientObservation => new CatalogueNutrientObservation(
                    nutrient: Nutrient::from($nutrient->nutrient),
                    basis: NutrientBasis::from($nutrient->basis),
                    value: $nutrient->value,
                    unit: NutrientUnit::from($nutrient->unit),
                    provenance: NutrientProvenance::Imported,
                    source: CatalogueItemSource::OpenFoodFacts,
                    sourceField: $nutrient->sourceField,
                    importedAt: $importedAt,
                ),
                $product->nutrientData,
            ),
        );
    }

    private function safeImageUrl(?string $imageUrl): ?string
    {
        if ($imageUrl === null
            || strlen($imageUrl) > 2048
            || filter_var($imageUrl, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($imageUrl);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || ! $this->isOpenFoodFactsHost($host)) {
            return null;
        }

        return $imageUrl;
    }

    private function isOpenFoodFactsHost(string $host): bool
    {
        foreach (['openfoodfacts.org', 'openfoodfacts.net'] as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    private function safeText(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === ''
            || mb_strlen($value) > $maxLength
            || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1
        ) {
            return null;
        }

        return $value;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function safeList(array $values): array
    {
        $safe = [];

        foreach (array_slice($values, 0, 50) as $value) {
            $value = $this->safeText($value, 255);

            if ($value !== null && ! in_array($value, $safe, true)) {
                $safe[] = $value;
            }
        }

        return $safe;
    }
}
