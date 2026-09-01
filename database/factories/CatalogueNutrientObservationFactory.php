<?php

namespace Database\Factories;

use App\Domain\Catalogue\CatalogueItemSource;
use App\Domain\Nutrition\CatalogueNutritionNormalizer;
use App\Domain\Nutrition\Nutrient;
use App\Domain\Nutrition\NutrientBasis;
use App\Domain\Nutrition\NutrientProvenance;
use App\Domain\Nutrition\NutrientUnit;
use App\Domain\Nutrition\NutrientValueStatus;
use App\Models\CatalogueItemVersion;
use App\Models\CatalogueNutrientObservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CatalogueNutrientObservation> */
class CatalogueNutrientObservationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'catalogue_item_version_id' => CatalogueItemVersion::factory(),
            'nutrient' => Nutrient::Protein,
            'basis' => NutrientBasis::Per100Gram,
            'value' => '7.250000000000000000',
            'threshold_value' => null,
            'unit' => NutrientUnit::Gram,
            'status' => NutrientValueStatus::Known,
            'provenance' => NutrientProvenance::Imported,
            'source' => CatalogueItemSource::OpenFoodFacts,
            'source_field' => 'proteins_100g',
            'source_scale' => 2,
            'precision_reduced' => false,
            'source_observed_at' => null,
            'imported_at' => now()->utc(),
            'normalization_policy_version' => CatalogueNutritionNormalizer::POLICY_VERSION,
        ];
    }
}
