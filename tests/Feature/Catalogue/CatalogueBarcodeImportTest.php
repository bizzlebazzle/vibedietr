<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Catalogue\CatalogueBarcodeImportResult;
use App\Domain\Catalogue\CatalogueBarcodeImportStatus;
use App\Domain\Catalogue\CatalogueImportCreator;
use App\Domain\Catalogue\CatalogueImportData;
use App\Domain\Catalogue\CatalogueItemOrigin;
use App\Domain\Catalogue\CatalogueItemSource;
use App\Domain\Catalogue\CatalogueItemStatus;
use App\Domain\Catalogue\ImportBarcodeIntoCatalogue;
use App\Domain\Catalogue\ServingAmountBasis;
use App\Domain\Measurements\StandardUnit;
use App\Domain\Nutrition\Nutrient;
use App\Domain\Nutrition\NutrientBasis;
use App\Domain\Nutrition\NutrientNormalizationWarning;
use App\Domain\Nutrition\NutrientProvenance;
use App\Integrations\OpenFoodFacts\OpenFoodFactsClient;
use App\Integrations\OpenFoodFacts\OpenFoodFactsLookupStatus;
use App\Livewire\Ingredients\Form;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\CatalogueNutrientValue;
use App\Models\Ingredient;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class CatalogueBarcodeImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'catalogue.read_cutover' => true,
            'services.openfoodfacts.attempts' => 1,
            'services.openfoodfacts.backoff_ms' => [0],
        ]);
        Http::preventStrayRequests();
    }

    public function test_successful_import_creates_one_approved_versioned_shared_product_with_mapped_facts(): void
    {
        Http::fake(['*' => Http::response($this->fullResponse())]);
        $submitter = User::factory()->create();

        $this->assertTrue(Schema::hasColumns('catalogue_item_versions', [
            'name',
            'keywords',
            'categories',
            'image_url',
            'name_source',
            'keywords_source',
            'categories_source',
            'package_source',
            'serving_source',
            'image_source',
        ]));

        $lookup = app(OpenFoodFactsClient::class)->lookup('0012345678905');
        $result = app(ImportBarcodeIntoCatalogue::class)->import(
            $submitter,
            '  0012345678905  ',
            $lookup,
        );

        $this->assertSame(CatalogueBarcodeImportStatus::Created, $result->status);
        $item = $result->item;
        $this->assertNotNull($item);
        $this->assertSame(CatalogueItemOrigin::Barcode, $item->origin);
        $this->assertSame(CatalogueItemSource::OpenFoodFacts, $item->source);
        $this->assertSame(CatalogueItemStatus::Approved, $item->status);
        $this->assertSame('0012345678905', $item->barcode);
        $this->assertSame('0012345678905', $item->source_identifier);
        $this->assertSame($submitter->id, $item->submitted_by_user_id);
        $this->assertCount(1, $item->versions);

        $version = $item->currentVersion;
        $this->assertNotNull($version);
        $this->assertSame(1, $version->version_number);
        $this->assertSame('Mapped multipack', $version->name);
        $this->assertSame(['one'], $version->keywords);
        $this->assertSame(['en:test'], $version->categories);
        $this->assertSame(4, $version->package_count);
        $this->assertSame('can', $version->item_type);
        $this->assertSame('400.000000000000000000', $version->amount_per_item);
        $this->assertSame(StandardUnit::Gram, $version->amount_per_item_unit);
        $this->assertSame('200.000000000000000000', $version->serving_amount);
        $this->assertSame(StandardUnit::Gram, $version->serving_amount_unit);
        $this->assertSame(ServingAmountBasis::Source, $version->serving_amount_basis);
        $this->assertSame('https://images.openfoodfacts.org/images/products/front.jpg', $version->image_url);
        $this->assertSame(CatalogueItemSource::OpenFoodFacts, $version->name_source);
        $this->assertSame(CatalogueItemSource::OpenFoodFacts, $version->package_source);
        $this->assertSame(CatalogueItemSource::OpenFoodFacts, $version->serving_source);
        $this->assertSame(CatalogueItemSource::OpenFoodFacts, $version->image_source);
        $this->assertSame(11, $version->nutrientValues()->count());
        $this->assertSame(11, $version->nutrientObservations()->count());

        $protein = $version->nutrientValues()
            ->where('nutrient', Nutrient::Protein)
            ->where('basis', NutrientBasis::Per100Gram)
            ->firstOrFail();
        $this->assertSame('0.000000000000000000', $protein->value);
        $this->assertSame(NutrientProvenance::Imported, $protein->provenance);
        $this->assertSame('proteins_100g', $protein->sourceObservation->source_field);
        $this->assertSame(CatalogueItemSource::OpenFoodFacts, $protein->sourceObservation->source);
        $this->assertSame(4, $version->nutrientObservations()->where('source_field', 'fat_100g')->value('source_scale'));
        $this->assertSame('0.140000000000000000', $version->nutrientValues()->where('nutrient', Nutrient::Sodium)->value('value'));

        $kj = $version->nutrientValues()
            ->where('nutrient', Nutrient::EnergyKj)
            ->where('basis', NutrientBasis::Per100Gram)
            ->firstOrFail();
        $this->assertSame(NutrientProvenance::Derived, $kj->provenance);
        $this->assertSame(NutrientNormalizationWarning::EnergySourceConflict, $kj->normalization_warning);
        $this->assertSame('100.000000000000000000', $kj->value);

        $this->get(route('catalogue.show', $item))
            ->assertOk()
            ->assertSee('Mapped multipack')
            ->assertSee('0012345678905');
    }

    public function test_repeated_scan_reuses_identity_without_provider_refresh_or_provenance_replacement(): void
    {
        Http::fake(['*' => Http::response($this->fullResponse())]);
        $firstScanner = User::factory()->create();
        $laterScanner = User::factory()->create();
        $lookup = app(OpenFoodFactsClient::class)->lookup('0012345678905');
        $first = app(ImportBarcodeIntoCatalogue::class)->import($firstScanner, '0012345678905', $lookup);

        Http::fake();
        $existing = app(ImportBarcodeIntoCatalogue::class)->findExisting('0012345678905');
        $second = app(ImportBarcodeIntoCatalogue::class)->import($laterScanner, '0012345678905', $lookup);

        $this->assertTrue($existing?->is($first->item));
        $this->assertSame(CatalogueBarcodeImportStatus::Reused, $second->status);
        $this->assertTrue($second->item?->is($first->item));
        $this->assertSame(1, CatalogueItem::query()->count());
        $this->assertSame(1, $first->item?->versions()->count());
        $this->assertSame($firstScanner->id, $first->item->refresh()->submitted_by_user_id);
        Http::assertNothingSent();
    }

    public function test_unique_collision_loads_the_winner_without_duplicate_version_graph(): void
    {
        Http::fake(['*' => Http::response($this->fullResponse())]);
        $firstScanner = User::factory()->create();
        $secondScanner = User::factory()->create();
        $lookup = app(OpenFoodFactsClient::class)->lookup('0012345678905');

        $creator = new class($firstScanner) implements CatalogueImportCreator
        {
            public function __construct(private readonly User $winnerSubmitter) {}

            public function createOrReuse(
                User $submitter,
                string $barcode,
                CatalogueImportData $mapped,
            ): CatalogueBarcodeImportResult {
                $winner = CatalogueItem::factory()->barcodeBacked($barcode)->submittedBy($this->winnerSubmitter)->create();
                $version = CatalogueItemVersion::factory()->for($winner)->create([
                    'version_number' => 1,
                    'name' => $mapped->name,
                    'name_source' => CatalogueItemSource::OpenFoodFacts,
                ]);
                $winner->setCurrentVersion($version);

                throw new QueryException(
                    'testing',
                    'insert into catalogue_items',
                    [],
                    new \PDOException('duplicate barcode', 23000),
                );
            }
        };
        $this->app->instance(CatalogueImportCreator::class, $creator);

        $result = app(ImportBarcodeIntoCatalogue::class)->import(
            $secondScanner,
            '0012345678905',
            $lookup,
        );

        $this->assertSame(CatalogueBarcodeImportStatus::Reused, $result->status);
        $this->assertSame(1, CatalogueItem::query()->count());
        $this->assertSame(1, CatalogueItemVersion::query()->count());
        $this->assertSame($firstScanner->id, $result->item->submitted_by_user_id);
        $this->assertNotNull($result->item->current_catalogue_item_version_id);
    }

    public function test_partial_product_can_import_without_fabricated_optional_facts(): void
    {
        Http::fake(['*' => Http::response($this->partialResponse())]);
        $user = User::factory()->create();
        $lookup = app(OpenFoodFactsClient::class)->lookup('0000000000123');

        $result = app(ImportBarcodeIntoCatalogue::class)->import($user, '0000000000123', $lookup);

        $version = $result->item?->currentVersion;
        $this->assertSame(CatalogueBarcodeImportStatus::Created, $result->status);
        $this->assertNotNull($version);
        $this->assertNull($version->name);
        $this->assertNull($version->package_count);
        $this->assertNull($version->amount_per_item);
        $this->assertNull($version->serving_amount);
        $this->assertNull($version->image_url);
        $this->assertSame(0, $version->nutrientValues()->count());
        $this->get(route('catalogue.show', $result->item))->assertOk()->assertSee('Unnamed catalogue item');
    }

    public function test_unapproved_existing_barcode_is_not_refetched_or_reused_by_an_ordinary_user(): void
    {
        $pending = CatalogueItem::factory()->barcodeBacked('0099999999999')->create([
            'status' => CatalogueItemStatus::Pending,
        ]);
        Http::fake();

        Livewire::actingAs(User::factory()->create())->test(Form::class)
            ->set('barcode', '0099999999999')
            ->call('fetchFromOff')
            ->assertNoRedirect()
            ->assertDispatched(
                'notify',
                type: 'error',
                message: 'That barcode is not currently available for import.',
            );

        $this->assertSame(CatalogueItemStatus::Pending, $pending->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_livewire_verified_save_creates_shared_product_without_legacy_dual_write(): void
    {
        Http::fake(['*' => Http::response($this->fullResponse())]);
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(Form::class)
            ->set('barcode', '0012345678905')
            ->call('fetchFromOff')
            ->call('save')
            ->assertRedirect();

        $item = CatalogueItem::query()->sole();
        $this->assertSame('0012345678905', $item->barcode);
        $this->assertSame(0, Ingredient::query()->count());
        $this->assertSame(1, $item->versions()->count());
    }

    public function test_invalid_barcode_and_provider_failures_create_no_catalogue_graph(): void
    {
        Http::fake();
        Livewire::actingAs(User::factory()->create())->test(Form::class)
            ->set('barcode', str_repeat('1', 65))
            ->call('fetchFromOff')
            ->assertHasErrors('barcode');
        Http::assertNothingSent();

        Http::fake(['*' => Http::response([
            'status' => 'failure',
            'result' => ['id' => 'product_not_found'],
        ], 404)]);
        Livewire::actingAs(User::factory()->create())->test(Form::class)
            ->set('barcode', '1234567890123')
            ->call('fetchFromOff');

        $this->assertSame(0, CatalogueItem::query()->count());
        $this->assertSame(0, CatalogueNutrientValue::query()->count());
    }

    public function test_unsupported_image_reference_is_ignored_without_failing_import(): void
    {
        Http::fake(['*' => Http::response($this->fullResponse([
            'image_front_url' => 'https://images.example/private.jpg',
        ]))]);
        $user = User::factory()->create();
        $lookup = app(OpenFoodFactsClient::class)->lookup('0012345678905');

        $result = app(ImportBarcodeIntoCatalogue::class)->import($user, '0012345678905', $lookup);

        $this->assertSame(OpenFoodFactsLookupStatus::Success, $lookup->status);
        $this->assertNull($result->item?->currentVersion?->image_url);
        $this->assertNull($result->item?->currentVersion?->image_source);
    }

    public function test_submitter_deletion_preserves_import_and_later_scans_reuse_it(): void
    {
        Http::fake(['*' => Http::response($this->fullResponse())]);
        $submitter = User::factory()->create();
        $lookup = app(OpenFoodFactsClient::class)->lookup('0012345678905');
        $created = app(ImportBarcodeIntoCatalogue::class)->import($submitter, '0012345678905', $lookup);
        $submitter->delete();

        $reused = app(ImportBarcodeIntoCatalogue::class)->import(
            User::factory()->create(),
            '0012345678905',
            $lookup,
        );

        $this->assertNull($created->item?->refresh()->submitted_by_user_id);
        $this->assertSame(CatalogueBarcodeImportStatus::Reused, $reused->status);
        $this->assertSame(1, CatalogueItem::query()->count());
        $this->get(route('catalogue.show', $created->item))->assertOk();
    }

    /** @param array<string, mixed> $overrides */
    private function fullResponse(array $overrides = []): array
    {
        return [
            'status' => 'success',
            'result' => ['id' => 'product_found'],
            'product' => array_replace([
                'code' => '0012345678905',
                'product_name' => 'Mapped multipack',
                'keywords' => ['one'],
                'categories_tags' => ['en:test'],
                'quantity' => '4 cans x 400 g',
                'serving_quantity' => '200',
                'serving_size' => '200 g',
                'nutriments' => [
                    'energy-kcal_100g' => '100',
                    'energy-kj_100g' => '999',
                    'proteins_100g' => '0',
                    'fat_100g' => '8.2500',
                    'saturated-fat_100g' => '2.1',
                    'carbohydrates_100g' => '12.5',
                    'sugars_100g' => '3.75',
                    'fiber_100g' => '1.25',
                    'salt_100g' => '0.35',
                    'sodium_100g' => '140',
                    'sodium_unit' => 'mg',
                    'fat_serving' => '2.5',
                    'fat_unit' => 'g',
                ],
                'image_front_url' => 'https://images.openfoodfacts.org/images/products/front.jpg',
            ], $overrides),
        ];
    }

    /** @return array<string, mixed> */
    private function partialResponse(): array
    {
        return [
            'status' => 'success',
            'result' => ['id' => 'product_found'],
            'product' => [
                'code' => '0000000000123',
                'nutriments' => [],
            ],
        ];
    }
}
