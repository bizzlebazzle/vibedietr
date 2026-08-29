<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Catalogue\CatalogueItemOrigin;
use App\Domain\Catalogue\CatalogueItemSource;
use App\Domain\Catalogue\CatalogueItemStatus;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class CatalogueItemModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_and_barcode_backed_identities_are_explicit_and_internally_identified(): void
    {
        $manual = CatalogueItem::factory()->manual()->create();
        $barcode = CatalogueItem::factory()->barcodeBacked('0012345678905')->create([
            'source_identifier' => 'provider-product-42',
        ]);

        $this->assertSame(CatalogueItemOrigin::Manual, $manual->origin);
        $this->assertNull($manual->barcode);
        $this->assertSame(CatalogueItemSource::Manual, $manual->source);
        $this->assertSame(CatalogueItemStatus::Pending, $manual->status);
        $this->assertSame(CatalogueItemOrigin::Barcode, $barcode->origin);
        $this->assertSame('0012345678905', $barcode->barcode);
        $this->assertSame(CatalogueItemSource::OpenFoodFacts, $barcode->source);
        $this->assertSame(CatalogueItemStatus::Approved, $barcode->status);
        $this->assertNotSame($barcode->barcode, (string) $barcode->getKey());
        $this->assertNotSame($barcode->source_identifier, (string) $barcode->getKey());
        $this->assertNotSame($manual->getKey(), $barcode->getKey());
    }

    public function test_multiple_null_barcodes_work_but_duplicate_barcodes_do_not(): void
    {
        CatalogueItem::factory()->manual()->count(2)->create();
        CatalogueItem::factory()->barcodeBacked('0000000000123')->create();

        $this->assertSame(3, CatalogueItem::query()->count());
        $this->assertSame('0000000000123', CatalogueItem::query()->whereNotNull('barcode')->value('barcode'));

        $this->expectException(QueryException::class);
        CatalogueItem::factory()->barcodeBacked('0000000000123')->create();
    }

    public function test_source_identifiers_are_nullable_and_provider_pairs_are_lookup_indexes_not_identity_constraints(): void
    {
        $manual = CatalogueItem::factory()->manual()->create();
        $first = CatalogueItem::factory()->barcodeBacked()->create(['source_identifier' => 'shared-provider-id']);
        $second = CatalogueItem::factory()->barcodeBacked()->create(['source_identifier' => 'shared-provider-id']);

        $this->assertNull($manual->source_identifier);
        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertSame(
            2,
            CatalogueItem::query()
                ->where('source', CatalogueItemSource::OpenFoodFacts)
                ->where('source_identifier', 'shared-provider-id')
                ->count(),
        );
    }

    public function test_submitter_is_nullable_provenance_and_database_user_deletion_nulls_it(): void
    {
        $submitter = User::factory()->create();
        $anonymous = CatalogueItem::factory()->manual()->create();
        $submitted = CatalogueItem::factory()->manual()->submittedBy($submitter)->create();

        $this->assertNull($anonymous->submitter);
        $this->assertTrue($submitted->submitter->is($submitter));
        $this->assertTrue($submitter->submittedCatalogueItems->contains($submitted));

        $submitter->delete();

        $this->assertTrue($submitted->refresh()->exists);
        $this->assertNull($submitted->submitted_by_user_id);
        $this->assertNull($submitted->submitter);
        $this->assertSame(CatalogueItemSource::Manual, $submitted->source);
    }

    public function test_versions_can_be_historical_and_one_can_be_selected_as_current(): void
    {
        $item = CatalogueItem::factory()->barcodeBacked()->create();
        $first = CatalogueItemVersion::factory()->for($item)->create(['version_number' => 1]);
        $second = CatalogueItemVersion::factory()->for($item)->create(['version_number' => 2]);

        $item->setCurrentVersion($second);

        $this->assertCount(2, $item->versions);
        $this->assertTrue($first->catalogueItem->is($item));
        $this->assertTrue($item->refresh()->currentVersion->is($second));
        $this->assertSame(1, $first->version_number);
    }

    public function test_current_version_assignment_rejects_a_version_from_another_item(): void
    {
        $item = CatalogueItem::factory()->create();
        $otherVersion = CatalogueItemVersion::factory()->create();

        $this->expectException(LogicException::class);
        $item->setCurrentVersion($otherVersion);
    }

    public function test_sensitive_identity_and_provenance_fields_are_guarded_from_mass_assignment(): void
    {
        $this->expectException(MassAssignmentException::class);

        (new CatalogueItem)->fill([
            'origin' => 'barcode',
            'submitted_by_user_id' => 999,
            'status' => 'approved',
        ]);
    }

    public function test_version_identity_fields_are_guarded_from_mass_assignment(): void
    {
        $this->expectException(MassAssignmentException::class);

        (new CatalogueItemVersion)->fill(['catalogue_item_id' => 1, 'version_number' => 99]);
    }

    public function test_database_rejects_invalid_bounded_status(): void
    {
        $this->expectException(QueryException::class);

        CatalogueItem::query()->getConnection()->table('catalogue_items')->insert([
            'origin' => 'manual',
            'barcode' => null,
            'submitted_by_user_id' => null,
            'source' => 'manual',
            'source_identifier' => null,
            'introduced_at' => now(),
            'status' => 'withdrawn',
            'current_catalogue_item_version_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
