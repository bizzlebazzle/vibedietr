<?php

namespace Tests\Unit\Domain;

use App\Domain\Nutrition\Nutrient;
use App\Domain\Nutrition\NutrientBasis;
use App\Domain\Nutrition\NutrientDisplayFormatter;
use App\Domain\Nutrition\NutrientRegistry;
use App\Domain\Nutrition\NutrientUnit;
use App\Domain\Nutrition\NutrientUnitConverter;
use App\Domain\Nutrition\NutrientValueStatus;
use App\Domain\Shared\Decimal;
use Brick\Math\RoundingMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NutrientDefinitionsTest extends TestCase
{
    public function test_every_product_specification_nutrient_is_defined_once(): void
    {
        $expected = [
            'energy_kcal', 'energy_kj', 'fat', 'saturated_fat', 'carbohydrates',
            'sugars', 'fibre', 'protein', 'salt', 'sodium',
        ];
        $actual = NutrientRegistry::stableIdentifiers();

        $this->assertEqualsCanonicalizing($expected, $actual);
        $this->assertCount(count($actual), array_unique($actual));
    }

    #[DataProvider('nutrientMetadata')]
    public function test_nutrients_have_approved_units_and_precision(
        Nutrient $nutrient,
        NutrientUnit $canonicalUnit,
        NutrientUnit $displayUnit,
        int $displayPrecision,
    ): void {
        $definition = NutrientRegistry::definition($nutrient);

        $this->assertSame($canonicalUnit, $definition->canonicalStorageUnit);
        $this->assertSame($displayUnit, $definition->preferredDisplayUnit);
        $this->assertSame(38, $definition->storagePrecision);
        $this->assertSame(18, $definition->storageScale);
        $this->assertSame(50, $definition->calculationPrecision);
        $this->assertSame(24, $definition->divisionGuardScale);
        $this->assertSame($displayPrecision, $definition->displayPrecision);
        $this->assertSame(RoundingMode::HALF_UP, $definition->displayRounding);
    }

    /** @return iterable<string, array{Nutrient, NutrientUnit, NutrientUnit, int}> */
    public static function nutrientMetadata(): iterable
    {
        yield 'kcal' => [Nutrient::EnergyKcal, NutrientUnit::Kilocalorie, NutrientUnit::Kilocalorie, 0];
        yield 'kJ' => [Nutrient::EnergyKj, NutrientUnit::Kilocalorie, NutrientUnit::Kilojoule, 0];
        yield 'fat' => [Nutrient::Fat, NutrientUnit::Gram, NutrientUnit::Gram, 1];
        yield 'saturated fat' => [Nutrient::SaturatedFat, NutrientUnit::Gram, NutrientUnit::Gram, 1];
        yield 'carbohydrates' => [Nutrient::Carbohydrates, NutrientUnit::Gram, NutrientUnit::Gram, 1];
        yield 'sugars' => [Nutrient::Sugars, NutrientUnit::Gram, NutrientUnit::Gram, 1];
        yield 'fibre' => [Nutrient::Fibre, NutrientUnit::Gram, NutrientUnit::Gram, 1];
        yield 'protein' => [Nutrient::Protein, NutrientUnit::Gram, NutrientUnit::Gram, 1];
        yield 'salt' => [Nutrient::Salt, NutrientUnit::Gram, NutrientUnit::Gram, 2];
        yield 'sodium' => [Nutrient::Sodium, NutrientUnit::Gram, NutrientUnit::Milligram, 0];
    }

    public function test_every_supported_basis_is_explicit_on_every_nutrient(): void
    {
        foreach (NutrientRegistry::all() as $definition) {
            $this->assertSame(NutrientBasis::cases(), $definition->supportedBases);
        }
    }

    public function test_kcal_is_authoritative_and_kj_is_derived_from_it(): void
    {
        $kcal = NutrientRegistry::definition(Nutrient::EnergyKcal);
        $kilojoules = NutrientRegistry::definition(Nutrient::EnergyKj);

        $this->assertTrue($kcal->authoritative);
        $this->assertFalse($kcal->derived);
        $this->assertFalse($kilojoules->authoritative);
        $this->assertTrue($kilojoules->derived);
        $this->assertSame(Nutrient::EnergyKcal, $kilojoules->derivedFrom);
        $this->assertSame('4.184', $kilojoules->derivationFactor);
    }

    public function test_energy_conversion_uses_the_exact_approved_factor(): void
    {
        $converter = new NutrientUnitConverter;

        $this->assertTrue($converter->convert('1', NutrientUnit::Kilocalorie, NutrientUnit::Kilojoule)->isEqualTo('4.184'));
        $this->assertTrue(
            $converter->convert('4.184', NutrientUnit::Kilojoule, NutrientUnit::Kilocalorie)->isEqualTo('1'),
        );
    }

    public function test_import_and_existing_code_aliases_resolve_to_stable_nutrients(): void
    {
        $this->assertSame(Nutrient::Fibre, NutrientRegistry::find('fiber')->id);
        $this->assertSame(Nutrient::Protein, NutrientRegistry::find('proteins')->id);
        $this->assertSame(Nutrient::SaturatedFat, NutrientRegistry::find('saturated-fat')->id);
        $this->assertSame(Nutrient::Carbohydrates, NutrientRegistry::find('carbohydrate')->id);
    }

    public function test_display_rounding_does_not_mutate_the_stored_value(): void
    {
        $formatter = new NutrientDisplayFormatter;
        $stored = '12.250000000000000000';

        $this->assertSame('12.3 g', $formatter->format(Nutrient::Fat, $stored));
        $this->assertSame('12.250000000000000000', $stored);
        $this->assertSame('0.35 g', $formatter->format(Nutrient::Salt, '0.345'));
    }

    public function test_zero_small_positive_missing_trace_and_below_limit_have_distinct_displays(): void
    {
        $formatter = new NutrientDisplayFormatter;

        $this->assertSame('0 kcal', $formatter->format(Nutrient::EnergyKcal, '0'));
        $this->assertSame('0.0 g', $formatter->format(Nutrient::Protein, '0'));
        $this->assertSame('0.00 g', $formatter->format(Nutrient::Salt, '0'));
        $this->assertSame('<0.1 g', $formatter->format(Nutrient::Fat, '0.049'));
        $this->assertSame('<1 mg', $formatter->format(Nutrient::Sodium, '0.0004'));
        $this->assertSame('Not available', $formatter->format(Nutrient::Fat, null, NutrientValueStatus::Missing));
        $this->assertSame('Trace', $formatter->format(Nutrient::Fat, null, NutrientValueStatus::Trace));
        $this->assertSame('<0.03 mg', $formatter->format(Nutrient::Sodium, null, NutrientValueStatus::BelowLimit, '0.00003'));
    }

    public function test_very_small_values_and_large_aggregates_remain_representable(): void
    {
        $this->assertSame('0.000000000000000001', Decimal::forStorage(Decimal::parse('0.000000000000000001')));
        $this->assertSame(
            '99999999999999999999.000000000000000000',
            Decimal::forStorage(Decimal::parse('99999999999999999999')),
        );
    }
}
