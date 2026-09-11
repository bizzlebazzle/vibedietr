<?php

namespace Tests\Feature\Nutrition;

use App\Domain\Catalogue\CatalogueItemSource;
use App\Domain\Catalogue\ServingAmountBasis;
use App\Domain\Measurements\CustomUnit;
use App\Domain\Measurements\StandardUnit;
use App\Domain\Nutrition\CalculationQuantityConverter;
use App\Domain\Nutrition\QuantityConversionExclusionReason;
use App\Domain\Shared\Decimal;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CalculationQuantityConverterTest extends TestCase
{
    use RefreshDatabase;

    private CalculationQuantityConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new CalculationQuantityConverter;
    }

    #[DataProvider('sameDimensionConversions')]
    public function test_standard_same_dimension_quantities_convert_without_food_evidence(
        string $quantity,
        StandardUnit $from,
        StandardUnit $to,
        string $expected,
    ): void {
        $result = $this->converter->convert($quantity, $from, $to);

        $this->assertFalse($result->isExcluded());
        $this->assertTrue($result->convertedQuantity()->isEqualTo($expected));
        $this->assertSame($to, $result->unit);
        $this->assertNull($result->foodConversion);
    }

    /** @return iterable<string, array{string, StandardUnit, StandardUnit, string}> */
    public static function sameDimensionConversions(): iterable
    {
        yield 'mass' => ['2.5', StandardUnit::Kilogram, StandardUnit::Gram, '2500'];
        yield 'volume' => ['0.125', StandardUnit::Litre, StandardUnit::Millilitre, '125'];
    }

    public function test_approved_sourced_food_serving_conversion_retains_version_source_and_reliability(): void
    {
        $version = CatalogueItemVersion::factory()
            ->for(CatalogueItem::factory()->approved())
            ->directlySourcedServing()
            ->create(['serving_source' => CatalogueItemSource::Manual]);

        $result = $this->converter->convert('2', StandardUnit::Serving, StandardUnit::Kilogram, $version);

        $this->assertTrue($result->convertedQuantity()->isEqualTo('0.4'));
        $evidence = $result->foodConversion;
        $this->assertNotNull($evidence);
        $this->assertSame((string) $version->getKey(), $evidence->catalogueItemVersionId);
        $this->assertSame(CatalogueItemSource::Manual, $evidence->source);
        $this->assertSame(ServingAmountBasis::Source->value, $evidence->basis);
        $this->assertTrue($evidence->reliable);
    }

    public function test_reliably_derived_serving_uses_package_source_and_preserves_formula_basis(): void
    {
        $version = CatalogueItemVersion::factory()
            ->for(CatalogueItem::factory()->approved())
            ->reliablyDerivedServing()
            ->create(['package_source' => CatalogueItemSource::OpenFoodFacts]);

        $result = $this->converter->convert('400', StandardUnit::Gram, StandardUnit::Serving, $version);

        $this->assertTrue($result->convertedQuantity()->isEqualTo('2'));
        $evidence = $result->foodConversion;
        $this->assertNotNull($evidence);
        $this->assertSame(CatalogueItemSource::OpenFoodFacts, $evidence->source);
        $this->assertSame(
            ServingAmountBasis::AmountPerItemDividedByServingsPerItem->value,
            $evidence->basis,
        );
    }

    public function test_sourced_amount_per_item_is_an_explicit_item_to_mass_conversion(): void
    {
        $version = CatalogueItemVersion::factory()
            ->for(CatalogueItem::factory()->approved())
            ->singleItem()
            ->create(['package_source' => CatalogueItemSource::Manual]);

        $result = $this->converter->convert('1.5', StandardUnit::Item, StandardUnit::Gram, $version);

        $this->assertTrue($result->convertedQuantity()->isEqualTo('600'));
        $evidence = $result->foodConversion;
        $this->assertNotNull($evidence);
        $this->assertSame('amount_per_item', $evidence->basis);
    }

    #[DataProvider('unsupportedConversions')]
    public function test_unsupported_conversions_return_an_explicit_exclusion(
        StandardUnit|CustomUnit $from,
        StandardUnit|CustomUnit $to,
        QuantityConversionExclusionReason $expected,
    ): void {
        $result = $this->converter->convert('1', $from, $to);

        $this->assertTrue($result->isExcluded());
        $this->assertSame($expected, $result->exclusionReason);
        $this->assertNull($result->quantity);
    }

    /** @return iterable<string, array{StandardUnit|CustomUnit, StandardUnit|CustomUnit, QuantityConversionExclusionReason}> */
    public static function unsupportedConversions(): iterable
    {
        yield 'custom unit' => [new CustomUnit('bunch'), StandardUnit::Gram, QuantityConversionExclusionReason::CustomUnit];
        yield 'unrelated count units' => [StandardUnit::Clove, StandardUnit::Piece, QuantityConversionExclusionReason::UnsupportedCountUnit];
        yield 'count without food data' => [StandardUnit::Serving, StandardUnit::Gram, QuantityConversionExclusionReason::FoodConversionMissing];
        yield 'mass to volume' => [StandardUnit::Gram, StandardUnit::Millilitre, QuantityConversionExclusionReason::InvalidDimensionCombination];
    }

    public function test_missing_food_conversion_fields_are_excluded_instead_of_treated_as_zero(): void
    {
        $version = CatalogueItemVersion::factory()->for(CatalogueItem::factory()->approved())->create();

        $result = $this->converter->convert('2', StandardUnit::Serving, StandardUnit::Gram, $version);

        $this->assertSame(QuantityConversionExclusionReason::FoodConversionMissing, $result->exclusionReason);
        $this->assertNull($result->quantity);
    }

    public function test_unapproved_food_context_and_missing_provenance_are_not_reliable_conversions(): void
    {
        $pending = CatalogueItemVersion::factory()->directlySourcedServing()->create([
            'serving_source' => CatalogueItemSource::Manual,
        ]);
        $approvedWithoutSource = CatalogueItemVersion::factory()
            ->for(CatalogueItem::factory()->approved())
            ->directlySourcedServing()
            ->create();

        $this->assertSame(
            QuantityConversionExclusionReason::FoodContextNotApproved,
            $this->converter->convert('1', StandardUnit::Serving, StandardUnit::Gram, $pending)->exclusionReason,
        );
        $this->assertSame(
            QuantityConversionExclusionReason::FoodConversionProvenanceMissing,
            $this->converter->convert('1', StandardUnit::Serving, StandardUnit::Gram, $approvedWithoutSource)->exclusionReason,
        );
    }

    public function test_food_conversion_division_keeps_guard_precision_until_storage_boundary(): void
    {
        $version = CatalogueItemVersion::factory()
            ->for(CatalogueItem::factory()->approved())
            ->create([
                'amount_per_item' => '3',
                'amount_per_item_unit' => StandardUnit::Gram,
                'package_source' => CatalogueItemSource::Manual,
            ]);

        $result = $this->converter->convert('1', StandardUnit::Gram, StandardUnit::Item, $version);

        $this->assertGreaterThanOrEqual(Decimal::DIVISION_GUARD_SCALE, $result->convertedQuantity()->getScale());
        $this->assertSame('0.333333333333333333', Decimal::forStorage($result->convertedQuantity()));
    }
}
