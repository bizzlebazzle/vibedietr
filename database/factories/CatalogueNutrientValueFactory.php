<?php

namespace Database\Factories;

use App\Domain\Nutrition\CatalogueNutritionNormalizer;
use App\Domain\Nutrition\Nutrient;
use App\Domain\Nutrition\NutrientBasis;
use App\Domain\Nutrition\NutrientProvenance;
use App\Domain\Nutrition\NutrientUnit;
use App\Domain\Nutrition\NutrientValueStatus;
use App\Models\CatalogueItemVersion;
use App\Models\CatalogueNutrientObservation;
use App\Models\CatalogueNutrientValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CatalogueNutrientValue> */
class CatalogueNutrientValueFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'catalogue_item_version_id' => CatalogueItemVersion::factory(),
            'source_observation_id' => function (array $attributes): string {
                return CatalogueNutrientObservation::factory()->create([
                    'catalogue_item_version_id' => $attributes['catalogue_item_version_id'],
                ])->getKey();
            },
            'nutrient' => Nutrient::Protein,
            'basis' => NutrientBasis::Per100Gram,
            'value' => '7.250000000000000000',
            'threshold_value' => null,
            'unit' => NutrientUnit::Gram,
            'status' => NutrientValueStatus::Known,
            'provenance' => NutrientProvenance::Imported,
            'derivation' => null,
            'normalization_warning' => null,
            'normalization_policy_version' => CatalogueNutritionNormalizer::POLICY_VERSION,
        ];
    }
}
