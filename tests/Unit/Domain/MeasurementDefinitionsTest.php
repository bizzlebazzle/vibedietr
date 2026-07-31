<?php

namespace Tests\Unit\Domain;

use App\Domain\Measurements\CustomUnit;
use App\Domain\Measurements\Exceptions\IncompatibleDimensions;
use App\Domain\Measurements\Exceptions\NonConvertibleUnit;
use App\Domain\Measurements\MeasurementDimension;
use App\Domain\Measurements\MeasurementUnitRegistry;
use App\Domain\Measurements\StandardUnit;
use App\Domain\Measurements\UnitConverter;
use App\Domain\Shared\Decimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MeasurementDefinitionsTest extends TestCase
{
    private UnitConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new UnitConverter;
    }

    public function test_standard_unit_identifiers_are_unique(): void
    {
        $identifiers = array_map(fn ($definition) => $definition->id->value, MeasurementUnitRegistry::all());

        $this->assertSame($identifiers, array_values(array_unique($identifiers)));
    }

    public function test_mass_volume_and_count_units_have_distinct_dimensions(): void
    {
        $this->assertSame(MeasurementDimension::Mass, MeasurementUnitRegistry::definition(StandardUnit::Gram)->dimension);
        $this->assertSame(MeasurementDimension::Volume, MeasurementUnitRegistry::definition(StandardUnit::Tablespoon)->dimension);
        $this->assertSame(MeasurementDimension::Count, MeasurementUnitRegistry::definition(StandardUnit::Clove)->dimension);
    }

    #[DataProvider('safeAliases')]
    public function test_safe_aliases_normalize_to_standard_units(string $alias, StandardUnit $expected): void
    {
        $this->assertSame($expected, MeasurementUnitRegistry::findStandard($alias));
    }

    /** @return iterable<string, array{string, StandardUnit}> */
    public static function safeAliases(): iterable
    {
        yield 'grams' => ['grams', StandardUnit::Gram];
        yield 'kilograms case-insensitive' => ['KILOGRAMS', StandardUnit::Kilogram];
        yield 'British spelling' => ['millilitres', StandardUnit::Millilitre];
        yield 'American spelling' => ['liters', StandardUnit::Litre];
        yield 'punctuated fluid ounce' => ['fl. oz.', StandardUnit::FluidOunce];
        yield 'item alias' => ['items', StandardUnit::Item];
    }

    public function test_unknown_and_ambiguous_units_are_preserved_as_custom_units(): void
    {
        foreach (['pinch', 'handful', 'bunch', 'sprig', 'to taste', 'T', 't'] as $input) {
            $unit = MeasurementUnitRegistry::normalize($input);

            $this->assertInstanceOf(CustomUnit::class, $unit);
            $this->assertSame($input, $unit->originalText);
            $this->assertSame(MeasurementDimension::Custom, $unit->dimension());
        }
    }

    public function test_mass_conversions_use_exact_decimal_factors(): void
    {
        $this->assertTrue($this->converter->convert('1000', StandardUnit::Milligram, StandardUnit::Gram)->isEqualTo('1'));
        $this->assertTrue($this->converter->convert('1', StandardUnit::Kilogram, StandardUnit::Gram)->isEqualTo('1000'));
        $this->assertTrue($this->converter->convert('1', StandardUnit::Ounce, StandardUnit::Gram)->isEqualTo('28.349523125'));
        $this->assertTrue($this->converter->convert('1', StandardUnit::Pound, StandardUnit::Gram)->isEqualTo('453.59237'));
    }

    public function test_volume_conversions_follow_the_approved_jurisdictions(): void
    {
        $this->assertTrue($this->converter->convert('1', StandardUnit::Teaspoon, StandardUnit::Millilitre)->isEqualTo('5'));
        $this->assertTrue($this->converter->convert('1', StandardUnit::Tablespoon, StandardUnit::Millilitre)->isEqualTo('15'));
        $this->assertTrue($this->converter->convert('1', StandardUnit::FluidOunce, StandardUnit::Millilitre)->isEqualTo('29.5735295625'));
        $this->assertTrue($this->converter->convert('1', StandardUnit::Cup, StandardUnit::Millilitre)->isEqualTo('236.5882365'));
        $this->assertTrue($this->converter->convert('1', StandardUnit::Pint, StandardUnit::Millilitre)->isEqualTo('473.176473'));
        $this->assertTrue($this->converter->convert('1', StandardUnit::Quart, StandardUnit::Millilitre)->isEqualTo('946.352946'));
        $this->assertTrue($this->converter->convert('1', StandardUnit::Gallon, StandardUnit::Millilitre)->isEqualTo('3785.411784'));
    }

    public function test_reverse_and_repeated_conversions_preserve_approved_precision(): void
    {
        $original = Decimal::parse('123.456789012345678901');
        $value = $original;

        for ($iteration = 0; $iteration < 10; $iteration++) {
            $value = $this->converter->convert((string) $value, StandardUnit::Gram, StandardUnit::Ounce);
            $value = $this->converter->convert((string) $value, StandardUnit::Ounce, StandardUnit::Gram);
        }

        $this->assertTrue($value->minus($original)->abs()->isLessThanOrEqualTo('0.000000000000000001'));
    }

    public function test_conversion_does_not_round_at_an_intermediate_storage_scale(): void
    {
        $converted = $this->converter->convert('0.0000000000000000001', StandardUnit::Kilogram, StandardUnit::Ounce);

        $this->assertFalse($converted->isZero());
        $this->assertGreaterThanOrEqual(Decimal::DIVISION_GUARD_SCALE, $converted->getScale());
    }

    #[DataProvider('incompatibleConversions')]
    public function test_cross_dimension_conversions_are_rejected(StandardUnit $from, StandardUnit $to): void
    {
        $this->expectException(IncompatibleDimensions::class);

        $this->converter->convert('1', $from, $to);
    }

    /** @return iterable<string, array{StandardUnit, StandardUnit}> */
    public static function incompatibleConversions(): iterable
    {
        yield 'mass to volume' => [StandardUnit::Gram, StandardUnit::Millilitre];
        yield 'volume to mass' => [StandardUnit::Cup, StandardUnit::Gram];
        yield 'count to mass' => [StandardUnit::Clove, StandardUnit::Gram];
    }

    public function test_count_units_only_support_identity_conversion(): void
    {
        $this->assertTrue($this->converter->convert('2.5', StandardUnit::Clove, StandardUnit::Clove)->isEqualTo('2.5'));

        $this->expectException(NonConvertibleUnit::class);
        $this->converter->convert('1', StandardUnit::Clove, StandardUnit::Piece);
    }

    public function test_custom_units_cannot_be_converted_without_an_explicit_mapping(): void
    {
        $this->expectException(NonConvertibleUnit::class);

        $this->converter->convert('1', new CustomUnit('bunch'), StandardUnit::Gram);
    }

    public function test_unrelated_custom_units_cannot_be_converted(): void
    {
        $this->expectException(NonConvertibleUnit::class);

        $this->converter->convert('1', new CustomUnit('pinch'), new CustomUnit('handful'));
    }

    public function test_unknown_destination_is_preserved_then_rejected_clearly(): void
    {
        $destination = MeasurementUnitRegistry::normalize('mystery scoop');

        $this->assertInstanceOf(CustomUnit::class, $destination);
        $this->assertFalse($this->converter->canConvert(StandardUnit::Gram, $destination));
    }

    public function test_storage_serialization_preserves_the_approved_normalized_precision_and_range(): void
    {
        $this->assertSame('0.000000000000000001', Decimal::forStorage(Decimal::parse('0.0000000000000000006')));
        $this->assertSame(
            '99999999999999999999.999999999999999999',
            Decimal::forStorage(Decimal::parse('99999999999999999999.999999999999999999')),
        );
    }
}
