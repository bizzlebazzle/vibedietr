<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Catalogue\CatalogueItemSource;
use App\Domain\Catalogue\CatalogueItemStatus;
use App\Domain\Catalogue\LegacyIngredientBackfill;
use App\Domain\Catalogue\LegacyIngredientClassification;
use App\Domain\Catalogue\LegacyIngredientClassifier;
use App\Domain\Catalogue\LegacyIngredientReviewReason;
use App\Models\CatalogueItem;
use App\Models\Ingredient;
use App\Models\LegacyIngredientCatalogueMapping;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LegacyIngredientBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_database_succeeds_and_reconciles_zero_rows(): void
    {
        $this->artisan('catalogue:backfill-legacy-ingredients')
            ->expectsOutputToContain('Eligible legacy rows')
            ->assertSuccessful();

        $this->assertDatabaseCount('catalogue_items', 0);
        $this->assertDatabaseCount('legacy_ingredient_catalogue_mappings', 0);
    }

    public function test_mixed_fixture_is_classified_without_changing_any_legacy_row(): void
    {
        $user = User::factory()->create();
        $manual = Ingredient::factory()->for($user)->manual()->withNutrition()->unusualUnit()->create([
            'name' => 'Hand entered saffron',
            'quantity' => '2.500',
            'serving_quantity' => '0.125',
            'serving_quantity_unit' => 'g',
            'recommended_servings' => '20.00',
        ]);
        $imported = Ingredient::factory()->for($user)->barcodeImported()->create([
            'barcode' => '0012345678905',
            'barcode_imported_at' => '2026-08-03 14:15:16',
        ]);
        $ambiguous = Ingredient::factory()->for($user)->legacyBarcode()->create([
            'barcode' => '0099999999999',
        ]);
        $firstDuplicate = Ingredient::factory()->for($user)->barcodeImported()->create([
            'barcode' => '0077777777777',
        ]);
        $secondDuplicate = Ingredient::factory()->for($user)->barcodeImported()->create([
            'barcode' => '0077777777777',
        ]);
        $before = $this->legacySnapshot();

        $this->artisan('catalogue:backfill-legacy-ingredients', ['--chunk' => 2])
            ->expectsOutputToContain('Legacy manual')
            ->expectsOutputToContain('Verified imported')
            ->expectsOutputToContain('Ambiguous barcode')
            ->expectsOutputToContain('Duplicate')
            ->assertSuccessful();

        $this->assertSame($before, $this->legacySnapshot());
        $this->assertDatabaseCount('ingredients', 5);
        $this->assertDatabaseCount('legacy_ingredient_catalogue_mappings', 5);
        $this->assertDatabaseCount('catalogue_items', 2);
        $this->assertDatabaseCount('catalogue_item_versions', 0);

        $manualMapping = LegacyIngredientCatalogueMapping::query()
            ->where('ingredient_id', $manual->id)
            ->sole();
        $importedMapping = LegacyIngredientCatalogueMapping::query()
            ->where('ingredient_id', $imported->id)
            ->sole();
        $ambiguousMapping = LegacyIngredientCatalogueMapping::query()
            ->where('ingredient_id', $ambiguous->id)
            ->sole();

        $this->assertSame(LegacyIngredientClassification::LegacyManual, $manualMapping->classification);
        $this->assertSame($user->id, $manualMapping->legacy_user_id);
        $this->assertSame('sprig', $manualMapping->legacy_snapshot['quantity_unit']);
        $this->assertNotNull($manualMapping->legacy_snapshot['nutriments']);
        $this->assertSame(CatalogueItemStatus::Pending, $manualMapping->catalogueItem->status);
        $this->assertSame($user->id, $manualMapping->catalogueItem->submitted_by_user_id);
        $this->assertNull($manualMapping->catalogueItem->current_catalogue_item_version_id);

        $this->assertSame(LegacyIngredientClassification::VerifiedImported, $importedMapping->classification);
        $this->assertSame(CatalogueItemSource::OpenFoodFacts, $importedMapping->catalogueItem->source);
        $this->assertSame('0012345678905', $importedMapping->catalogueItem->source_identifier);
        $this->assertSame('0012345678905', $importedMapping->catalogueItem->barcode);
        $this->assertSame(
            '2026-08-03T14:15:16+00:00',
            $importedMapping->catalogueItem->introduced_at->toIso8601String(),
        );
        $this->assertStringStartsWith(
            '2026-08-03 14:15:16',
            (string) $importedMapping->legacy_snapshot['barcode_imported_at'],
        );

        $this->assertSame(LegacyIngredientClassification::AmbiguousBarcode, $ambiguousMapping->classification);
        $this->assertSame(
            LegacyIngredientReviewReason::UnverifiedLegacyBarcode,
            $ambiguousMapping->review_reason,
        );
        $this->assertNull($ambiguousMapping->catalogue_item_id);

        foreach ([$firstDuplicate, $secondDuplicate] as $duplicate) {
            $mapping = LegacyIngredientCatalogueMapping::query()
                ->where('ingredient_id', $duplicate->id)
                ->sole();
            $this->assertSame(LegacyIngredientClassification::Duplicate, $mapping->classification);
            $this->assertSame(LegacyIngredientReviewReason::DuplicateBarcode, $mapping->review_reason);
            $this->assertNull($mapping->catalogue_item_id);
        }
    }

    public function test_malformed_and_incomplete_import_provenance_remain_pending_review(): void
    {
        $malformed = Ingredient::factory()->legacyBarcode()->create(['barcode' => ' 0012345678905 ']);
        $missingSource = Ingredient::factory()->barcodeImported()->create(['barcode_source' => null]);
        $missingTime = Ingredient::factory()->barcodeImported()->create(['barcode_imported_at' => null]);

        $this->artisan('catalogue:backfill-legacy-ingredients')->assertSuccessful();

        $this->assertMappingReason($malformed, LegacyIngredientReviewReason::MalformedBarcode);
        $this->assertMappingReason($missingSource, LegacyIngredientReviewReason::MissingImportSource);
        $this->assertMappingReason($missingTime, LegacyIngredientReviewReason::MissingImportTimestamp);
        $this->assertSame(' 0012345678905 ', $malformed->refresh()->barcode);
        $this->assertDatabaseCount('catalogue_items', 0);
    }

    public function test_dry_run_reports_classifications_and_writes_nothing(): void
    {
        Ingredient::factory()->manual()->create();
        Ingredient::factory()->barcodeImported()->create();
        Ingredient::factory()->legacyBarcode()->create();
        $before = $this->legacySnapshot();

        $this->artisan('catalogue:backfill-legacy-ingredients', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('Would process')
            ->assertSuccessful();

        $this->assertSame($before, $this->legacySnapshot());
        $this->assertDatabaseCount('catalogue_items', 0);
        $this->assertDatabaseCount('legacy_ingredient_catalogue_mappings', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_partial_progress_resumes_and_completed_run_is_idempotent(): void
    {
        $first = Ingredient::factory()->manual()->create();
        Ingredient::factory()->manual()->count(2)->create();
        $row = DB::table('ingredients')->where('id', $first->id)->first();
        $classifier = app(LegacyIngredientClassifier::class);
        app(LegacyIngredientBackfill::class)->persist($row, $classifier->classify($row, false));
        $firstCandidateId = LegacyIngredientCatalogueMapping::query()
            ->where('ingredient_id', $first->id)
            ->value('catalogue_item_id');

        $this->artisan('catalogue:backfill-legacy-ingredients', ['--chunk' => 1])
            ->assertSuccessful();

        $this->assertDatabaseCount('legacy_ingredient_catalogue_mappings', 3);
        $this->assertDatabaseCount('catalogue_items', 3);
        $this->assertSame(
            $firstCandidateId,
            LegacyIngredientCatalogueMapping::query()
                ->where('ingredient_id', $first->id)
                ->value('catalogue_item_id'),
        );

        $this->artisan('catalogue:backfill-legacy-ingredients')
            ->expectsOutputToContain('Newly processed')
            ->assertSuccessful();
        $this->assertDatabaseCount('legacy_ingredient_catalogue_mappings', 3);
        $this->assertDatabaseCount('catalogue_items', 3);
    }

    public function test_source_change_after_mapping_is_reported_without_rewriting_evidence(): void
    {
        $ingredient = Ingredient::factory()->manual()->create(['name' => 'Original wording']);
        $this->artisan('catalogue:backfill-legacy-ingredients')->assertSuccessful();
        $mapping = LegacyIngredientCatalogueMapping::query()->sole();
        $checksum = $mapping->legacy_checksum;

        DB::table('ingredients')->where('id', $ingredient->id)->update(['name' => 'Changed wording']);

        $this->artisan('catalogue:backfill-legacy-ingredients')
            ->expectsOutputToContain('Source changed after mapping')
            ->assertFailed();

        $this->assertSame($checksum, $mapping->refresh()->legacy_checksum);
        $this->assertSame('Original wording', $mapping->legacy_snapshot['name']);
        $this->assertDatabaseCount('catalogue_items', 1);
    }

    public function test_command_lock_and_database_uniqueness_prevent_concurrent_duplicate_work(): void
    {
        $ingredient = Ingredient::factory()->manual()->create();
        $lock = Cache::lock('catalogue:backfill-legacy-ingredients', 60);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('catalogue:backfill-legacy-ingredients')
                ->expectsOutputToContain('already running')
                ->assertFailed();
        } finally {
            $lock->release();
        }

        $this->artisan('catalogue:backfill-legacy-ingredients')->assertSuccessful();

        $this->expectException(QueryException::class);
        DB::table('legacy_ingredient_catalogue_mappings')->insert([
            'ingredient_id' => $ingredient->id,
            'legacy_user_id' => $ingredient->user_id,
            'catalogue_item_id' => null,
            'classification' => 'legacy_manual',
            'review_reason' => null,
            'legacy_snapshot' => '{}',
            'legacy_checksum' => str_repeat('0', 64),
            'backfill_version' => 1,
            'backfilled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_unexpected_candidate_constraint_failure_preserves_prior_progress_and_legacy_rows(): void
    {
        $manual = Ingredient::factory()->manual()->create();
        $imported = Ingredient::factory()->barcodeImported()->create([
            'barcode' => '0012345678905',
        ]);
        CatalogueItem::factory()->barcodeBacked('0012345678905')->create();
        $before = $this->legacySnapshot();

        $this->artisan('catalogue:backfill-legacy-ingredients', ['--chunk' => 1])
            ->expectsOutputToContain('Unexpected failure')
            ->assertFailed();

        $this->assertSame($before, $this->legacySnapshot());
        $this->assertDatabaseHas('legacy_ingredient_catalogue_mappings', [
            'ingredient_id' => $manual->id,
            'classification' => LegacyIngredientClassification::LegacyManual->value,
        ]);
        $this->assertDatabaseMissing('legacy_ingredient_catalogue_mappings', [
            'ingredient_id' => $imported->id,
        ]);
        $this->assertDatabaseCount('catalogue_items', 2);
    }

    public function test_mapping_and_candidate_survive_submitter_deletion_without_retaining_user_id(): void
    {
        $user = User::factory()->create();
        $ingredient = Ingredient::factory()->for($user)->manual()->create();
        $legacyId = $ingredient->id;
        $this->artisan('catalogue:backfill-legacy-ingredients')->assertSuccessful();
        $mapping = LegacyIngredientCatalogueMapping::query()->sole();
        $candidateId = $mapping->catalogue_item_id;

        $user->delete();

        $this->assertDatabaseMissing('ingredients', ['id' => $legacyId]);
        $this->assertDatabaseHas('legacy_ingredient_catalogue_mappings', [
            'ingredient_id' => $legacyId,
            'legacy_user_id' => null,
            'catalogue_item_id' => $candidateId,
        ]);
        $this->assertDatabaseHas('catalogue_items', [
            'id' => $candidateId,
            'submitted_by_user_id' => null,
        ]);
    }

    private function assertMappingReason(
        Ingredient $ingredient,
        LegacyIngredientReviewReason $reason,
    ): void {
        $mapping = LegacyIngredientCatalogueMapping::query()
            ->where('ingredient_id', $ingredient->id)
            ->sole();

        $this->assertSame(LegacyIngredientClassification::AmbiguousBarcode, $mapping->classification);
        $this->assertSame($reason, $mapping->review_reason);
        $this->assertNull($mapping->catalogue_item_id);
    }

    private function legacySnapshot(): string
    {
        return json_encode(
            DB::table('ingredients')->orderBy('id')->get()->map(fn (object $row) => (array) $row)->all(),
            JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }
}
