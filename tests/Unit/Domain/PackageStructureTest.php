<?php

namespace Tests\Unit\Domain;

use App\Domain\Catalogue\PackageStructure;
use App\Domain\Catalogue\ServingAmountBasis;
use App\Domain\Measurements\Exceptions\IncompatibleDimensions;
use App\Domain\Measurements\StandardUnit;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PackageStructureTest extends TestCase
{
    public function test_single_item_and_multipack_keep_each_component_separate(): void
    {
        $single = PackageStructure::make(
            packageCount: 1,
            amountPerItem: '400',
            amountPerItemUnit: StandardUnit::Gram,
        );
        $multipack = PackageStructure::make(
            packageCount: 4,
            itemType: ' can ',
            amountPerItem: '400',
            amountPerItemUnit: 'g',
        );

        $this->assertSame(1, $single->packageCount);
        $this->assertSame('400', (string) $single->amountPerItem);
        $this->assertSame(StandardUnit::Gram, $single->amountPerItemUnit);
        $this->assertNull($single->servingsPerItem);
        $this->assertNull($single->servingAmount);
        $this->assertSame(4, $multipack->packageCount);
        $this->assertSame('can', $multipack->itemType);
        $this->assertSame('400', (string) $multipack->amountPerItem);
        $this->assertArrayNotHasKey('total_package_amount', $multipack->toAttributes());
    }

    public function test_serving_amount_is_reliably_and_deterministically_derived_without_changing_source_amount(): void
    {
        $first = PackageStructure::make(
            amountPerItem: '400',
            amountPerItemUnit: StandardUnit::Gram,
            servingsPerItem: '2',
        );
        $second = PackageStructure::make(
            amountPerItem: '400',
            amountPerItemUnit: StandardUnit::Gram,
            servingsPerItem: '2',
        );

        $this->assertSame('400', (string) $first->amountPerItem);
        $this->assertSame('200.000000000000000000', (string) $first->servingAmount);
        $this->assertSame(StandardUnit::Gram, $first->servingAmountUnit);
        $this->assertSame(
            ServingAmountBasis::AmountPerItemDividedByServingsPerItem,
            $first->servingAmountBasis,
        );
        $this->assertSame($first->toAttributes(), $second->toAttributes());
    }

    public function test_direct_source_serving_takes_precedence_over_a_derivable_value(): void
    {
        $structure = PackageStructure::make(
            amountPerItem: '400',
            amountPerItemUnit: StandardUnit::Gram,
            servingsPerItem: '4',
            servingAmount: '200',
            servingAmountUnit: StandardUnit::Gram,
        );

        $this->assertSame('200', (string) $structure->servingAmount);
        $this->assertSame(ServingAmountBasis::Source, $structure->servingAmountBasis);
    }

    #[DataProvider('partialStructures')]
    public function test_partial_source_data_is_valid(array $attributes): void
    {
        $structure = PackageStructure::make(...$attributes);

        $this->assertNull($structure->servingAmountBasis);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function partialStructures(): iterable
    {
        yield 'unknown' => [[]];
        yield 'count only' => [['packageCount' => 4]];
        yield 'count and item type' => [['packageCount' => 4, 'itemType' => 'can']];
        yield 'amount without package count' => [[
            'amountPerItem' => '400',
            'amountPerItemUnit' => StandardUnit::Gram,
        ]];
    }

    public function test_direct_serving_without_package_structure_is_valid(): void
    {
        $structure = PackageStructure::make(
            servingAmount: '200',
            servingAmountUnit: StandardUnit::Gram,
        );

        $this->assertNull($structure->packageCount);
        $this->assertNull($structure->amountPerItem);
        $this->assertSame(ServingAmountBasis::Source, $structure->servingAmountBasis);
    }

    #[DataProvider('invalidPairs')]
    public function test_amount_and_unit_pairs_reject_orphaned_values(array $attributes): void
    {
        $this->expectException(InvalidArgumentException::class);

        PackageStructure::make(...$attributes);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidPairs(): iterable
    {
        yield 'amount per item only' => [['amountPerItem' => '400']];
        yield 'amount per item unit only' => [['amountPerItemUnit' => StandardUnit::Gram]];
        yield 'serving amount only' => [['servingAmount' => '200']];
        yield 'serving amount unit only' => [['servingAmountUnit' => StandardUnit::Gram]];
    }

    #[DataProvider('invalidNonPositiveValues')]
    public function test_structural_values_must_be_positive_when_present(array $attributes): void
    {
        $this->expectException(InvalidArgumentException::class);

        PackageStructure::make(...$attributes);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidNonPositiveValues(): iterable
    {
        yield 'zero package count' => [['packageCount' => 0]];
        yield 'negative package count' => [['packageCount' => '-1']];
        yield 'zero amount per item' => [[
            'amountPerItem' => '0',
            'amountPerItemUnit' => StandardUnit::Gram,
        ]];
        yield 'negative amount per item' => [[
            'amountPerItem' => '-1',
            'amountPerItemUnit' => StandardUnit::Gram,
        ]];
        yield 'zero servings per item' => [['servingsPerItem' => '0']];
        yield 'negative servings per item' => [['servingsPerItem' => '-1']];
        yield 'zero serving amount' => [[
            'servingAmount' => '0',
            'servingAmountUnit' => StandardUnit::Gram,
        ]];
        yield 'negative serving amount' => [[
            'servingAmount' => '-1',
            'servingAmountUnit' => StandardUnit::Gram,
        ]];
    }

    public function test_mass_volume_and_count_amounts_use_fnd_06_units_and_same_dimension_conversion(): void
    {
        $mass = PackageStructure::make(
            amountPerItem: '0.5',
            amountPerItemUnit: StandardUnit::Kilogram,
            servingsPerItem: '2',
        );
        $volume = PackageStructure::make(
            amountPerItem: '0.33',
            amountPerItemUnit: StandardUnit::Litre,
        );
        $count = PackageStructure::make(
            amountPerItem: '6',
            amountPerItemUnit: StandardUnit::Item,
        );

        $this->assertTrue($mass->amountPerItemIn(StandardUnit::Gram)?->isEqualTo('500'));
        $this->assertTrue($mass->servingAmountIn(StandardUnit::Gram)?->isEqualTo('250'));
        $this->assertTrue($volume->amountPerItemIn(StandardUnit::Millilitre)?->isEqualTo('330'));
        $this->assertSame(StandardUnit::Item, $count->amountPerItemUnit);
    }

    public function test_mass_to_volume_conversion_is_not_performed(): void
    {
        $structure = PackageStructure::make(
            amountPerItem: '400',
            amountPerItemUnit: StandardUnit::Gram,
            servingsPerItem: '2',
        );

        $this->expectException(IncompatibleDimensions::class);

        $structure->servingAmountIn(StandardUnit::Millilitre);
    }

    public function test_missing_derivation_inputs_leave_serving_amount_null(): void
    {
        $missingAmount = PackageStructure::make(servingsPerItem: '2');
        $missingServings = PackageStructure::make(
            amountPerItem: '400',
            amountPerItemUnit: StandardUnit::Gram,
        );

        $this->assertNull($missingAmount->servingAmount);
        $this->assertNull($missingServings->servingAmount);
    }

    public function test_division_uses_storage_precision_without_floating_point(): void
    {
        $structure = PackageStructure::make(
            amountPerItem: '1',
            amountPerItemUnit: StandardUnit::Gram,
            servingsPerItem: '3',
        );

        $this->assertSame('0.333333333333333333', (string) $structure->servingAmount);
    }
}
