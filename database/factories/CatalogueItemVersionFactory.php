<?php

namespace Database\Factories;

use App\Domain\Catalogue\CatalogueItemSource;
use App\Domain\Catalogue\ServingAmountBasis;
use App\Domain\Measurements\StandardUnit;
use App\Domain\Nutrition\CatalogueNutrientObservation;
use App\Domain\Nutrition\CatalogueNutritionNormalizer;
use App\Domain\Nutrition\Nutrient;
use App\Domain\Nutrition\NutrientBasis;
use App\Domain\Nutrition\NutrientProvenance;
use App\Domain\Nutrition\NutrientUnit;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CatalogueItemVersion> */
class CatalogueItemVersionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'catalogue_item_id' => CatalogueItem::factory(),
            'version_number' => 1,
        ];
    }

    public function singleItem(): static
    {
        return $this->state(fn () => [
            'package_count' => 1,
            'item_type' => null,
            'amount_per_item' => '400',
            'amount_per_item_unit' => StandardUnit::Gram,
        ]);
    }

    public function multipack(): static
    {
        return $this->state(fn () => [
            'package_count' => 4,
            'item_type' => 'can',
            'amount_per_item' => '400',
            'amount_per_item_unit' => StandardUnit::Gram,
        ]);
    }

    public function partialPackage(): static
    {
        return $this->state(fn () => [
            'package_count' => 4,
            'item_type' => 'can',
        ]);
    }

    public function directlySourcedServing(): static
    {
        return $this->state(fn () => [
            'serving_amount' => '200',
            'serving_amount_unit' => StandardUnit::Gram,
            'serving_amount_basis' => ServingAmountBasis::Source,
        ]);
    }

    public function reliablyDerivedServing(): static
    {
        return $this->state(fn () => [
            'package_count' => 4,
            'item_type' => 'can',
            'amount_per_item' => '400',
            'amount_per_item_unit' => StandardUnit::Gram,
            'servings_per_item' => '2',
            'serving_amount' => '200',
            'serving_amount_unit' => StandardUnit::Gram,
            'serving_amount_basis' => ServingAmountBasis::AmountPerItemDividedByServingsPerItem,
        ]);
    }

    public function completeNutrition(): static
    {
        return $this->withNutrition([
            self::imported(Nutrient::EnergyKcal, '100'),
            self::imported(Nutrient::EnergyKj, '418.4'),
            self::imported(Nutrient::Fat, '8.25'),
            self::imported(Nutrient::SaturatedFat, '2.1'),
            self::imported(Nutrient::Carbohydrates, '12.5'),
            self::imported(Nutrient::Sugars, '3.75'),
            self::imported(Nutrient::Fibre, '1.25'),
            self::imported(Nutrient::Protein, '7.25'),
            self::imported(Nutrient::Salt, '0.35'),
            self::imported(Nutrient::Sodium, '140', NutrientUnit::Milligram),
        ]);
    }

    public function incompleteNutrition(): static
    {
        return $this->withNutrition([
            self::manual(Nutrient::Protein, '7.25'),
        ]);
    }

    public function per100GramNutrition(): static
    {
        return $this->withNutrition([
            self::manual(Nutrient::Protein, '7.25'),
        ]);
    }

    public function perServingNutrition(): static
    {
        return $this->directlySourcedServing()->withNutrition([
            self::manual(Nutrient::Protein, '4', basis: NutrientBasis::PerServing),
        ]);
    }

    public function importedNutrition(): static
    {
        return $this->withNutrition([self::imported(Nutrient::Protein, '7.25')]);
    }

    public function manuallySubmittedNutrition(): static
    {
        return $this->withNutrition([self::manual(Nutrient::Fibre, '2.5')]);
    }

    public function correctedNutrition(): static
    {
        return $this->withNutrition([
            new CatalogueNutrientObservation(
                Nutrient::Salt,
                NutrientBasis::Per100Gram,
                '0.3',
                NutrientUnit::Gram,
                NutrientProvenance::Corrected,
            ),
        ]);
    }

    public function kcalOnlyNutrition(): static
    {
        return $this->withNutrition([self::imported(Nutrient::EnergyKcal, '100')]);
    }

    public function kjOnlyNutrition(): static
    {
        return $this->withNutrition([self::imported(Nutrient::EnergyKj, '418.4')]);
    }

    public function conflictingEnergyNutrition(): static
    {
        return $this->withNutrition([
            self::imported(Nutrient::EnergyKcal, '100'),
            self::imported(Nutrient::EnergyKj, '999'),
        ]);
    }

    /** @param list<CatalogueNutrientObservation> $observations */
    private function withNutrition(array $observations): static
    {
        return $this->afterCreating(
            fn (CatalogueItemVersion $version) => app(CatalogueNutritionNormalizer::class)
                ->store($version, $observations),
        );
    }

    private static function imported(
        Nutrient $nutrient,
        string $value,
        ?NutrientUnit $unit = null,
        NutrientBasis $basis = NutrientBasis::Per100Gram,
    ): CatalogueNutrientObservation {
        return new CatalogueNutrientObservation(
            $nutrient,
            $basis,
            $value,
            $unit ?? self::sourceUnit($nutrient),
            NutrientProvenance::Imported,
            source: CatalogueItemSource::OpenFoodFacts,
            sourceField: $nutrient->value.'_'.$basis->value,
            importedAt: now()->toImmutable()->utc(),
        );
    }

    private static function manual(
        Nutrient $nutrient,
        string $value,
        ?NutrientUnit $unit = null,
        NutrientBasis $basis = NutrientBasis::Per100Gram,
    ): CatalogueNutrientObservation {
        return new CatalogueNutrientObservation(
            $nutrient,
            $basis,
            $value,
            $unit ?? self::sourceUnit($nutrient),
            NutrientProvenance::ManuallySubmitted,
        );
    }

    private static function sourceUnit(Nutrient $nutrient): NutrientUnit
    {
        return match ($nutrient) {
            Nutrient::EnergyKcal => NutrientUnit::Kilocalorie,
            Nutrient::EnergyKj => NutrientUnit::Kilojoule,
            default => NutrientUnit::Gram,
        };
    }
}
