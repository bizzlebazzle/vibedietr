<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Catalogue\PackageStructure;
use App\Domain\Catalogue\ServingAmountBasis;
use App\Domain\Measurements\StandardUnit;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CataloguePackageStructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_package_structure_persists_on_the_catalogue_version_with_null_unknowns(): void
    {
        $version = CatalogueItemVersion::factory()->create();

        $this->assertNull($version->package_count);
        $this->assertNull($version->amount_per_item);
        $this->assertNull($version->servings_per_item);
        $this->assertNull($version->serving_amount);
        $this->assertNull($version->serving_amount_basis);

        $version->replacePackageStructure(PackageStructure::make(
            packageCount: 4,
            itemType: 'can',
            amountPerItem: '400',
            amountPerItemUnit: StandardUnit::Gram,
            servingsPerItem: '2',
        ));
        $version->refresh();

        $this->assertSame(4, $version->package_count);
        $this->assertSame('can', $version->item_type);
        $this->assertSame('400.000000000000000000', $version->amount_per_item);
        $this->assertSame(StandardUnit::Gram, $version->amount_per_item_unit);
        $this->assertSame('2.000000000000000000', $version->servings_per_item);
        $this->assertSame('200.000000000000000000', $version->serving_amount);
        $this->assertSame(StandardUnit::Gram, $version->serving_amount_unit);
        $this->assertSame(
            ServingAmountBasis::AmountPerItemDividedByServingsPerItem,
            $version->serving_amount_basis,
        );
        $this->assertDatabaseMissing('catalogue_item_versions', [
            'id' => $version->getKey(),
            'amount_per_item' => '1600.000000000000000000',
        ]);
    }

    public function test_replacement_recalculates_derived_values_and_does_not_leave_stale_data(): void
    {
        $version = CatalogueItemVersion::factory()->reliablyDerivedServing()->create();

        $version->replacePackageStructure(PackageStructure::make(
            amountPerItem: '600',
            amountPerItemUnit: StandardUnit::Gram,
            servingsPerItem: '2',
        ));
        $this->assertSame('300.000000000000000000', $version->refresh()->serving_amount);

        $version->replacePackageStructure(PackageStructure::make(
            amountPerItem: '600',
            amountPerItemUnit: StandardUnit::Gram,
            servingsPerItem: '3',
        ));
        $this->assertSame('200.000000000000000000', $version->refresh()->serving_amount);

        $version->replacePackageStructure(PackageStructure::make(
            amountPerItem: '0.6',
            amountPerItemUnit: StandardUnit::Kilogram,
            servingsPerItem: '3',
        ));
        $this->assertSame(StandardUnit::Kilogram, $version->refresh()->serving_amount_unit);
        $this->assertSame('0.200000000000000000', $version->serving_amount);
    }

    public function test_model_rejects_a_stale_persisted_derived_value(): void
    {
        $version = CatalogueItemVersion::factory()->reliablyDerivedServing()->create();
        $version->forceFill(['amount_per_item' => '600']);

        $this->expectException(InvalidArgumentException::class);

        $version->save();
    }

    public function test_source_serving_is_not_overwritten_when_package_inputs_change(): void
    {
        $version = CatalogueItemVersion::factory()->create();
        $version->replacePackageStructure(PackageStructure::make(
            amountPerItem: '400',
            amountPerItemUnit: StandardUnit::Gram,
            servingsPerItem: '4',
            servingAmount: '200',
            servingAmountUnit: StandardUnit::Gram,
        ));

        $version->replacePackageStructure(PackageStructure::make(
            amountPerItem: '800',
            amountPerItemUnit: StandardUnit::Gram,
            servingsPerItem: '4',
            servingAmount: $version->serving_amount,
            servingAmountUnit: $version->serving_amount_unit,
        ));

        $this->assertSame('200.000000000000000000', $version->refresh()->serving_amount);
        $this->assertSame(ServingAmountBasis::Source, $version->serving_amount_basis);
    }

    public function test_copying_structure_to_a_new_version_preserves_history(): void
    {
        $item = CatalogueItem::factory()->create();
        $first = CatalogueItemVersion::factory()->for($item)->create(['version_number' => 1]);
        $first->replacePackageStructure(PackageStructure::make(
            packageCount: 6,
            itemType: 'bottle',
            amountPerItem: '330',
            amountPerItemUnit: StandardUnit::Millilitre,
        ));
        $second = CatalogueItemVersion::factory()->for($item)->create(['version_number' => 2]);

        $second->copyPackageStructureFrom($first);
        $second->replacePackageStructure(PackageStructure::make(
            packageCount: 12,
            itemType: 'bottle',
            amountPerItem: '330',
            amountPerItemUnit: StandardUnit::Millilitre,
        ));

        $this->assertSame(6, $first->refresh()->package_count);
        $this->assertSame(12, $second->refresh()->package_count);
        $this->assertSame('330.000000000000000000', $first->amount_per_item);
        $this->assertSame('330.000000000000000000', $second->amount_per_item);
    }

    public function test_factory_states_are_deterministic_and_valid(): void
    {
        $single = CatalogueItemVersion::factory()->singleItem()->create();
        $multipack = CatalogueItemVersion::factory()->multipack()->create();
        $partial = CatalogueItemVersion::factory()->partialPackage()->create();
        $source = CatalogueItemVersion::factory()->directlySourcedServing()->create();
        $derived = CatalogueItemVersion::factory()->reliablyDerivedServing()->create();

        $this->assertSame(1, $single->package_count);
        $this->assertSame(4, $multipack->package_count);
        $this->assertNull($partial->amount_per_item);
        $this->assertSame(ServingAmountBasis::Source, $source->serving_amount_basis);
        $this->assertSame(
            ServingAmountBasis::AmountPerItemDividedByServingsPerItem,
            $derived->serving_amount_basis,
        );
    }
}
