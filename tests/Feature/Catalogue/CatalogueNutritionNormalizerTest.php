<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Catalogue\CatalogueItemSource;
use App\Domain\Catalogue\CatalogueNutrientReadModel;
use App\Domain\Nutrition\CatalogueNutrientObservation as Input;
use App\Domain\Nutrition\CatalogueNutritionNormalizer;
use App\Domain\Nutrition\Nutrient;
use App\Domain\Nutrition\NutrientBasis;
use App\Domain\Nutrition\NutrientDerivation;
use App\Domain\Nutrition\NutrientDisplayFormatter;
use App\Domain\Nutrition\NutrientNormalizationWarning;
use App\Domain\Nutrition\NutrientProvenance;
use App\Domain\Nutrition\NutrientUnit;
use App\Domain\Nutrition\NutrientValueStatus;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\CatalogueNutrientObservation;
use App\Models\CatalogueNutrientValue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CatalogueNutritionNormalizerTest extends TestCase
{
    use RefreshDatabase;

    private CatalogueNutritionNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = app(CatalogueNutritionNormalizer::class);
    }

    public function test_every_supported_nutrient_round_trips_with_its_approved_canonical_unit(): void
    {
        $version = CatalogueItemVersion::factory()->create();

        $this->normalizer->store($version, [
            $this->imported(Nutrient::EnergyKcal, '100'),
            $this->imported(Nutrient::EnergyKj, '418.4'),
            $this->imported(Nutrient::Fat, '8.25'),
            $this->imported(Nutrient::SaturatedFat, '2.1'),
            $this->imported(Nutrient::Carbohydrates, '12.5'),
            $this->imported(Nutrient::Sugars, '3.75'),
            $this->imported(Nutrient::Fibre, '1.25'),
            $this->imported(Nutrient::Protein, '7.25'),
            $this->imported(Nutrient::Salt, '0.35'),
            $this->imported(Nutrient::Sodium, '140', NutrientUnit::Milligram),
        ]);

        $facts = $version->nutrientValues()->orderBy('nutrient')->get()->keyBy(
            fn (CatalogueNutrientValue $value): string => $value->nutrient->value,
        );

        $this->assertEqualsCanonicalizing(
            array_map(fn (Nutrient $nutrient): string => $nutrient->value, Nutrient::cases()),
            $facts->keys()->all(),
        );
        $this->assertSame(NutrientUnit::Kilocalorie, $facts['energy_kcal']->unit);
        $this->assertSame(NutrientUnit::Kilocalorie, $facts['energy_kj']->unit);
        $this->assertSame('0.140000000000000000', $facts['sodium']->value);
        $this->assertSame(NutrientUnit::Gram, $facts['sodium']->unit);
    }

    public function test_catalogue_bases_are_explicit_and_the_same_nutrient_can_use_each_basis(): void
    {
        $version = CatalogueItemVersion::factory()->directlySourcedServing()->create();

        $this->normalizer->store($version, [
            $this->manual(Nutrient::Protein, '10', NutrientBasis::Per100Gram),
            $this->manual(Nutrient::Protein, '9', NutrientBasis::Per100Millilitre),
            $this->manual(Nutrient::Protein, '4', NutrientBasis::PerServing),
        ]);

        $this->assertEqualsCanonicalizing(
            ['per_100g', 'per_100ml', 'per_serving'],
            $version->nutrientValues()->get()->map(
                fn (CatalogueNutrientValue $value): string => $value->basis->value,
            )->all(),
        );
    }

    public function test_recipe_only_basis_is_rejected_for_catalogue_nutrition(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->normalizer->store(CatalogueItemVersion::factory()->create(), [
            $this->manual(Nutrient::Protein, '5', NutrientBasis::PerRecipe),
        ]);
    }

    #[DataProvider('invalidUnitPairs')]
    public function test_invalid_nutrient_unit_pairs_are_rejected(Nutrient $nutrient, NutrientUnit $unit): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->normalizer->store(CatalogueItemVersion::factory()->create(), [
            new Input(
                $nutrient,
                NutrientBasis::Per100Gram,
                '5.2',
                $unit,
                NutrientProvenance::ManuallySubmitted,
            ),
        ]);
    }

    /** @return iterable<string, array{Nutrient, NutrientUnit}> */
    public static function invalidUnitPairs(): iterable
    {
        yield 'protein in kcal' => [Nutrient::Protein, NutrientUnit::Kilocalorie];
        yield 'kcal in grams' => [Nutrient::EnergyKcal, NutrientUnit::Gram];
        yield 'kJ identified as kcal' => [Nutrient::EnergyKj, NutrientUnit::Kilocalorie];
    }

    public function test_known_zero_and_missing_remain_distinct_without_fake_values(): void
    {
        $version = CatalogueItemVersion::factory()->create();

        $this->normalizer->store($version, [
            $this->manual(Nutrient::Fat, 0),
            $this->manual(Nutrient::Sugars, '0.0'),
            $this->manual(Nutrient::Salt, '0'),
            new Input(
                Nutrient::Protein,
                NutrientBasis::Per100Gram,
                null,
                NutrientUnit::Gram,
                NutrientProvenance::ManuallySubmitted,
                NutrientValueStatus::Missing,
            ),
        ]);

        $facts = $version->nutrientValues()->get()->keyBy(
            fn (CatalogueNutrientValue $value): string => $value->nutrient->value,
        );

        $this->assertSame('0.000000000000000000', $facts['fat']->value);
        $this->assertSame('0.000000000000000000', $facts['sugars']->value);
        $this->assertSame('0.000000000000000000', $facts['salt']->value);
        $this->assertNull($facts['protein']->value);
        $this->assertSame(NutrientValueStatus::Missing, $facts['protein']->status);
        $this->assertArrayNotHasKey('carbohydrates', $facts->all());
    }

    #[DataProvider('precisionValues')]
    public function test_source_precision_round_trips_without_display_rounding(
        string $source,
        int $scale,
        string $stored,
        string $display,
    ): void {
        $version = CatalogueItemVersion::factory()->create();

        $this->normalizer->store($version, [$this->manual(Nutrient::Protein, $source)]);

        $observation = $version->nutrientObservations()->sole();
        $fact = $version->nutrientValues()->sole();

        $this->assertSame($scale, $observation->source_scale);
        $this->assertSame($stored, $fact->value);
        $this->assertSame($display, (new NutrientDisplayFormatter)->format(Nutrient::Protein, $fact->value));
        $this->assertSame($stored, $fact->refresh()->value);
    }

    /** @return iterable<string, array{string, int, string, string}> */
    public static function precisionValues(): iterable
    {
        yield 'integer' => ['12', 0, '12.000000000000000000', '12.0 g'];
        yield 'one decimal' => ['12.4', 1, '12.400000000000000000', '12.4 g'];
        yield 'two decimals' => ['12.46', 2, '12.460000000000000000', '12.5 g'];
    }

    public function test_equal_numeric_values_retain_distinct_source_scale_metadata(): void
    {
        $versions = collect(['7', '7.0', '7.00'])->map(function (string $source): CatalogueItemVersion {
            $version = CatalogueItemVersion::factory()->create();
            $this->normalizer->store($version, [$this->manual(Nutrient::Protein, $source)]);

            return $version;
        });

        $this->assertSame(
            [0, 1, 2],
            $versions->map(
                fn (CatalogueItemVersion $version): int => $version->nutrientObservations()->sole()->source_scale,
            )->all(),
        );
        $this->assertSame(
            ['7.000000000000000000'],
            $versions->map(
                fn (CatalogueItemVersion $version): string => $version->nutrientValues()->sole()->value,
            )->unique()->values()->all(),
        );
    }

    public function test_scale_eighteen_is_retained_and_over_scale_reduction_is_recorded(): void
    {
        $exact = CatalogueItemVersion::factory()->create();
        $reduced = CatalogueItemVersion::factory()->create();

        $this->normalizer->store($exact, [$this->manual(Nutrient::Fibre, '0.003000000000000000')]);
        $this->normalizer->store($reduced, [$this->manual(Nutrient::Fibre, '1.1234567890123456785')]);

        $this->assertFalse($exact->nutrientObservations()->sole()->precision_reduced);
        $this->assertSame('0.003000000000000000', $exact->nutrientValues()->sole()->value);
        $this->assertTrue($reduced->nutrientObservations()->sole()->precision_reduced);
        $this->assertSame(19, $reduced->nutrientObservations()->sole()->source_scale);
        $this->assertSame('1.123456789012345679', $reduced->nutrientValues()->sole()->value);
        $this->assertSame(
            NutrientNormalizationWarning::SourcePrecisionReduced,
            $reduced->nutrientValues()->sole()->normalization_warning,
        );
    }

    public function test_kcal_only_derives_kj_from_the_canonical_value_once(): void
    {
        $version = CatalogueItemVersion::factory()->create();

        $this->normalizer->store($version, [$this->imported(Nutrient::EnergyKcal, '100')]);

        $facts = $version->nutrientValues()->get()->keyBy(
            fn (CatalogueNutrientValue $value): string => $value->nutrient->value,
        );

        $this->assertSame('100.000000000000000000', $facts['energy_kcal']->value);
        $this->assertSame(NutrientProvenance::Imported, $facts['energy_kcal']->provenance);
        $this->assertSame(NutrientProvenance::Derived, $facts['energy_kj']->provenance);
        $this->assertSame(NutrientDerivation::EnergyKjFromKcal, $facts['energy_kj']->derivation);
        $this->assertSame('418 kJ', (new NutrientDisplayFormatter)->format(
            Nutrient::EnergyKj,
            $facts['energy_kj']->value,
        ));
    }

    public function test_kj_only_derives_canonical_kcal_without_iterative_drift(): void
    {
        $version = CatalogueItemVersion::factory()->create();

        $this->normalizer->store($version, [$this->imported(Nutrient::EnergyKj, '1.234567890123456789')]);

        $facts = $version->nutrientValues()->get()->keyBy(
            fn (CatalogueNutrientValue $value): string => $value->nutrient->value,
        );

        $this->assertSame('0.295068807390883554', $facts['energy_kcal']->value);
        $this->assertSame($facts['energy_kcal']->value, $facts['energy_kj']->value);
        $this->assertSame(NutrientDerivation::EnergyKcalFromKj, $facts['energy_kcal']->derivation);
        $this->assertSame(NutrientDerivation::EnergyKjFromKcal, $facts['energy_kj']->derivation);
    }

    public function test_conflicting_energy_retains_both_observations_and_kcal_wins(): void
    {
        $version = CatalogueItemVersion::factory()->create();

        $this->normalizer->store($version, [
            $this->imported(Nutrient::EnergyKcal, '100'),
            $this->imported(Nutrient::EnergyKj, '999'),
        ]);

        $facts = $version->nutrientValues()->get()->keyBy(
            fn (CatalogueNutrientValue $value): string => $value->nutrient->value,
        );

        $this->assertCount(2, $version->nutrientObservations);
        $this->assertSame('999.000000000000000000', $version->nutrientObservations()
            ->where('nutrient', Nutrient::EnergyKj)
            ->sole()
            ->value);
        $this->assertSame('100.000000000000000000', $facts['energy_kcal']->value);
        $this->assertSame('100.000000000000000000', $facts['energy_kj']->value);
        $this->assertSame(
            NutrientNormalizationWarning::EnergySourceConflict,
            $facts['energy_kj']->normalization_warning,
        );
    }

    public function test_mixed_field_level_provenance_is_preserved_on_one_version(): void
    {
        $version = CatalogueItemVersion::factory()->create();

        $this->normalizer->store($version, [
            $this->imported(Nutrient::Protein, '7.25'),
            $this->manual(Nutrient::Fibre, '2.5'),
            new Input(
                Nutrient::Salt,
                NutrientBasis::Per100Gram,
                '0.3',
                NutrientUnit::Gram,
                NutrientProvenance::Corrected,
            ),
            $this->imported(Nutrient::EnergyKcal, '100'),
        ]);

        $facts = $version->nutrientValues()->get()->keyBy(
            fn (CatalogueNutrientValue $value): string => $value->nutrient->value,
        );

        $this->assertSame(NutrientProvenance::Imported, $facts['protein']->provenance);
        $this->assertSame(NutrientProvenance::ManuallySubmitted, $facts['fibre']->provenance);
        $this->assertSame(NutrientProvenance::Corrected, $facts['salt']->provenance);
        $this->assertSame(NutrientProvenance::Derived, $facts['energy_kj']->provenance);
    }

    public function test_versioned_nutrition_and_serving_facts_do_not_mutate_history(): void
    {
        $item = CatalogueItem::factory()->create();
        $first = CatalogueItemVersion::factory()->for($item)->directlySourcedServing()->create([
            'version_number' => 1,
            'serving_amount' => '40',
        ]);
        $second = CatalogueItemVersion::factory()->for($item)->directlySourcedServing()->create([
            'version_number' => 2,
            'serving_amount' => '50',
        ]);
        $this->normalizer->store($first, [
            new Input(
                Nutrient::Protein,
                NutrientBasis::PerServing,
                '4',
                NutrientUnit::Gram,
                NutrientProvenance::Imported,
                source: CatalogueItemSource::OpenFoodFacts,
            ),
        ]);
        $this->normalizer->store($second, [
            new Input(
                Nutrient::Protein,
                NutrientBasis::PerServing,
                '5',
                NutrientUnit::Gram,
                NutrientProvenance::Corrected,
            ),
        ]);

        $item->setCurrentVersion($second);

        $this->assertSame('4.000000000000000000', $first->nutrientValues()->sole()->value);
        $this->assertSame(NutrientProvenance::Imported, $first->nutrientValues()->sole()->provenance);
        $this->assertSame('40.000000000000000000', $first->refresh()->serving_amount);
        $this->assertSame('5.000000000000000000', $item->refresh()->currentVersion->nutrientValues()->sole()->value);
        $this->assertSame(
            NutrientProvenance::Corrected,
            $item->currentVersion->nutrientValues()->sole()->provenance,
        );
    }

    public function test_per_serving_is_not_derived_from_per_100g_by_nut_05(): void
    {
        $version = CatalogueItemVersion::factory()->reliablyDerivedServing()->create();

        $this->normalizer->store($version, [$this->manual(Nutrient::Protein, '10')]);

        $this->assertDatabaseMissing('catalogue_nutrient_values', [
            'catalogue_item_version_id' => $version->id,
            'basis' => NutrientBasis::PerServing->value,
        ]);
    }

    public function test_negative_unknown_duplicate_and_rewrite_inputs_are_rejected(): void
    {
        $version = CatalogueItemVersion::factory()->create();

        try {
            $this->normalizer->store($version, [$this->manual(Nutrient::Protein, '-1')]);
            $this->fail('Negative nutrition was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('catalogue_nutrient_values', 0);
        }

        $duplicate = $this->manual(Nutrient::Protein, '1');

        try {
            $this->normalizer->store($version, [$duplicate, $duplicate]);
            $this->fail('Duplicate nutrition was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('catalogue_nutrient_values', 0);
        }

        $this->normalizer->store($version, [$duplicate]);

        $this->expectException(LogicException::class);
        $this->normalizer->store($version, [$this->manual(Nutrient::Protein, '2')]);
    }

    public function test_database_unique_fact_and_cross_version_source_guards_hold(): void
    {
        $version = CatalogueItemVersion::factory()->create();
        $other = CatalogueItemVersion::factory()->create();
        $observation = CatalogueNutrientObservation::factory()->for($version, 'catalogueItemVersion')->create();

        try {
            CatalogueNutrientValue::factory()->for($other, 'catalogueItemVersion')->create([
                'source_observation_id' => $observation->id,
            ]);
            $this->fail('Cross-version observation reference was accepted.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $uniqueVersion = CatalogueItemVersion::factory()->create();
        $this->normalizer->store($uniqueVersion, [$this->manual(Nutrient::Fat, '1')]);

        $this->expectException(QueryException::class);
        CatalogueNutrientValue::query()->getConnection()->table('catalogue_nutrient_values')->insert([
            'id' => '01K3ZPBQ4DKW1QR2TTQTX3QRAB',
            'catalogue_item_version_id' => $uniqueVersion->id,
            'source_observation_id' => $uniqueVersion->nutrientObservations()->first()->id,
            'nutrient' => Nutrient::Fat->value,
            'basis' => NutrientBasis::Per100Gram->value,
            'value' => '2',
            'threshold_value' => null,
            'unit' => NutrientUnit::Gram->value,
            'status' => NutrientValueStatus::Known->value,
            'provenance' => NutrientProvenance::Corrected->value,
            'derivation' => null,
            'normalization_warning' => null,
            'normalization_policy_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_public_projection_is_explicit_and_omits_internal_provenance_metadata(): void
    {
        $version = CatalogueItemVersion::factory()->create();

        $this->normalizer->store($version, [
            new Input(
                Nutrient::Protein,
                NutrientBasis::Per100Gram,
                '7.25',
                NutrientUnit::Gram,
                NutrientProvenance::Imported,
                source: CatalogueItemSource::OpenFoodFacts,
                sourceField: 'proteins_100g',
                importedAt: now()->toImmutable(),
            ),
        ]);

        $value = $version->nutrientValues()->with('sourceObservation')->sole();
        $public = CatalogueNutrientReadModel::fromValue($value)->toArray();

        $this->assertSame('7.250000000000000000', $public['value']);
        $this->assertSame('per_100g', $public['basis']);
        $this->assertSame('g', $public['unit']);
        $this->assertSame('imported', $public['provenance']);
        $this->assertSame('openfoodfacts', $public['source']);
        $this->assertArrayNotHasKey('source_observation_id', $public);
        $this->assertArrayNotHasKey('source_field', $public);
        $this->assertArrayNotHasKey('imported_at', $public);
        $this->assertArrayNotHasKey('raw', $public);
    }

    private function imported(
        Nutrient $nutrient,
        string|int $value,
        ?NutrientUnit $unit = null,
    ): Input {
        return new Input(
            $nutrient,
            NutrientBasis::Per100Gram,
            $value,
            $unit ?? $this->sourceUnit($nutrient),
            NutrientProvenance::Imported,
            source: CatalogueItemSource::OpenFoodFacts,
            sourceField: $nutrient->value,
            importedAt: now()->toImmutable(),
        );
    }

    private function manual(
        Nutrient $nutrient,
        string|int $value,
        NutrientBasis $basis = NutrientBasis::Per100Gram,
    ): Input {
        return new Input(
            $nutrient,
            $basis,
            $value,
            $this->sourceUnit($nutrient),
            NutrientProvenance::ManuallySubmitted,
        );
    }

    private function sourceUnit(Nutrient $nutrient): NutrientUnit
    {
        return match ($nutrient) {
            Nutrient::EnergyKcal => NutrientUnit::Kilocalorie,
            Nutrient::EnergyKj => NutrientUnit::Kilojoule,
            default => NutrientUnit::Gram,
        };
    }
}
