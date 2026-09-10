<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Catalogue\CatalogueChangeProposalType;
use App\Domain\Catalogue\CatalogueImportCreator;
use App\Domain\Catalogue\CatalogueProviderRefreshRequester;
use App\Domain\Catalogue\CatalogueProviderRefreshState;
use App\Domain\Catalogue\ProcessCatalogueProviderRefresh;
use App\Integrations\OpenFoodFacts\OpenFoodFactsCatalogueMapper;
use App\Integrations\OpenFoodFacts\OpenFoodFactsProductMapper;
use App\Jobs\RefreshOpenFoodFactsCatalogueItem;
use App\Models\CatalogueCorrectionChange;
use App\Models\CatalogueCorrectionProposal;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\CatalogueProviderRefresh;
use App\Models\User;
use App\Queue\Exceptions\RetryableJobException;
use App\Queue\QueueName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CatalogueProviderRefreshProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openfoodfacts.base_url' => 'https://world.openfoodfacts.test',
            'services.openfoodfacts.api_version' => 'v3',
            'services.openfoodfacts.user_agent' => 'VibeDietr/Test',
            'services.openfoodfacts.connect_timeout' => 1,
            'services.openfoodfacts.timeout' => 2,
            'services.openfoodfacts.attempts' => 1,
            'services.openfoodfacts.backoff_ms' => [0],
            'services.openfoodfacts.max_retry_after' => 0,
        ]);
        Http::preventStrayRequests();
    }

    public function test_eligible_request_pins_base_dispatches_safe_correlated_job_and_coalesces_duplicates(): void
    {
        $item = $this->importBaseItem();
        Queue::fake();

        $first = app(CatalogueProviderRefreshRequester::class)->request($item->id);
        $second = app(CatalogueProviderRefreshRequester::class)->request($item->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($item->current_catalogue_item_version_id, $first->base_catalogue_item_version_id);
        $this->assertSame($item->source_identifier, $first->source_identifier);
        $this->assertSame(CatalogueProviderRefreshState::Queued, $first->state);
        $this->assertDatabaseCount('catalogue_provider_refreshes', 1);
        Queue::assertPushed(RefreshOpenFoodFactsCatalogueItem::class, 1);
        Queue::assertPushed(function (RefreshOpenFoodFactsCatalogueItem $job) use ($first): bool {
            $payload = serialize($job);

            return $job->refreshId === $first->id
                && $job->correlationId === $first->correlation_id
                && $job->queue === QueueName::DEFAULT
                && ! str_contains($payload, (string) $first->source_identifier)
                && ! str_contains($payload, 'Base product');
        });
    }

    public function test_manual_or_mismatched_source_identity_is_ineligible(): void
    {
        Queue::fake();
        $manual = CatalogueItem::factory()->approved()->create();
        CatalogueItemVersion::factory()->for($manual, 'catalogueItem')->current()->create();

        try {
            app(CatalogueProviderRefreshRequester::class)->request($manual->fresh()->id);
            $this->fail('A manual item was accepted for provider refresh.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('catalogue_provider_refreshes', 0);
        }

        $item = $this->importBaseItem();
        $item->forceFill(['source_identifier' => '9999999999999'])->save();

        $this->expectException(ValidationException::class);
        app(CatalogueProviderRefreshRequester::class)->request($item->id);
    }

    public function test_semantically_unchanged_provider_data_completes_without_proposal_or_version(): void
    {
        $item = $this->importBaseItem();
        $currentId = $item->current_catalogue_item_version_id;
        Queue::fake();
        $refresh = app(CatalogueProviderRefreshRequester::class)->request($item->id);
        Http::fake(['*' => Http::response($this->response())]);

        app(ProcessCatalogueProviderRefresh::class)->process($refresh->id);

        $this->assertSame(CatalogueProviderRefreshState::NoChange, $refresh->fresh()->state);
        $this->assertNull($refresh->fresh()->active_key);
        $this->assertSame($currentId, $item->fresh()->current_catalogue_item_version_id);
        $this->assertSame(1, $item->versions()->count());
        $this->assertSame(0, CatalogueCorrectionProposal::query()->count());
    }

    public function test_changed_fields_stage_one_typed_provider_proposal_without_mutating_current_truth(): void
    {
        $item = $this->importBaseItem();
        $currentId = $item->current_catalogue_item_version_id;
        Queue::fake();
        $refresh = app(CatalogueProviderRefreshRequester::class)->request($item->id);
        Http::fake(['*' => Http::response($this->response([
            'product_name' => 'Updated product',
            'nutriments' => [
                'energy-kcal_100g' => '100',
                'proteins_100g' => '8.25',
                'salt_100g' => '0.35',
            ],
        ]))]);

        $processor = app(ProcessCatalogueProviderRefresh::class);
        $processor->process($refresh->id);
        $processor->process($refresh->id);

        $proposal = CatalogueCorrectionProposal::query()->with('changes')->sole();
        $this->assertSame(CatalogueChangeProposalType::ProviderRefresh, $proposal->proposal_type);
        $this->assertNull($proposal->proposer_user_id);
        $this->assertSame($refresh->id, $proposal->provider_refresh_id);
        $this->assertSame($currentId, $proposal->base_catalogue_item_version_id);
        $this->assertSame(['name', 'nutrition.protein.per_100g'], $proposal->changes->pluck('field_key')->sort()->values()->all());
        $protein = $proposal->changes->firstWhere('field_key', 'nutrition.protein.per_100g');
        $this->assertSame('7.0', $protein->before_value['value']);
        $this->assertSame('8.25', $protein->proposed_value['value']);
        $this->assertSame('proteins_100g', $protein->provenance['source_field']);
        $this->assertSame(CatalogueProviderRefreshState::Staged, $refresh->fresh()->state);
        $this->assertSame($currentId, $item->fresh()->current_catalogue_item_version_id);
        $this->assertSame(1, $item->versions()->count());
        Http::assertSentCount(1);
    }

    public function test_provider_omissions_do_not_clear_package_serving_or_nutrition(): void
    {
        $item = $this->importBaseItem();
        $base = $item->currentVersion;
        Queue::fake();
        $refresh = app(CatalogueProviderRefreshRequester::class)->request($item->id);
        Http::fake(['*' => Http::response($this->response([
            'product_name' => 'Partial update',
            'keywords' => [],
            'categories_tags' => [],
            'nutriments' => [],
            'image_front_url' => null,
            'quantity' => null,
            'serving_quantity' => null,
            'serving_size' => null,
        ]))]);

        app(ProcessCatalogueProviderRefresh::class)->process($refresh->id);

        $proposal = CatalogueCorrectionProposal::query()->with('changes')->sole();
        $this->assertSame(['name'], $proposal->changes->pluck('field_key')->all());
        $this->assertSame('400.000000000000000000', $base->amount_per_item);
        $this->assertSame('200.000000000000000000', $base->serving_amount);
        $this->assertSame(3, $base->nutrientObservations()->count());
    }

    public function test_source_precision_change_is_material_even_when_numeric_value_is_equal(): void
    {
        $item = $this->importBaseItem();
        Queue::fake();
        $refresh = app(CatalogueProviderRefreshRequester::class)->request($item->id);
        Http::fake(['*' => Http::response($this->response([
            'nutriments' => [
                'energy-kcal_100g' => '100',
                'proteins_100g' => '7.00',
                'salt_100g' => '0.35',
            ],
        ]))]);

        app(ProcessCatalogueProviderRefresh::class)->process($refresh->id);

        /** @var CatalogueCorrectionChange $change */
        $change = CatalogueCorrectionProposal::query()->firstOrFail()->changes()
            ->where('field_key', 'nutrition.protein.per_100g')->sole();
        $this->assertSame('7.0', $change->before_value['value']);
        $this->assertSame('7.00', $change->proposed_value['value']);
        $this->assertSame(2, $change->proposed_value['source_scale']);
    }

    public function test_not_found_and_transient_failure_preserve_the_current_version(): void
    {
        $item = $this->importBaseItem();
        $currentId = $item->current_catalogue_item_version_id;
        Queue::fake();
        $notFound = app(CatalogueProviderRefreshRequester::class)->request($item->id);
        Http::fake(['*' => Http::response(['status' => 'failure', 'result' => ['id' => 'product_not_found']], 404)]);

        app(ProcessCatalogueProviderRefresh::class)->process($notFound->id);

        $this->assertSame(CatalogueProviderRefreshState::NotFound, $notFound->fresh()->state);
        $this->assertSame($currentId, $item->fresh()->current_catalogue_item_version_id);
        $this->assertSame(0, CatalogueCorrectionProposal::query()->count());

        $retry = app(CatalogueProviderRefreshRequester::class)->request($item->id);
        Http::fake(['*' => Http::failedConnection('private provider detail')]);

        try {
            app(ProcessCatalogueProviderRefresh::class)->process($retry->id);
            $this->fail('A transient provider failure did not request a bounded retry.');
        } catch (RetryableJobException $exception) {
            $this->assertSame('openfoodfacts_unavailable', $exception->safeErrorCode);
        }

        $this->assertSame(CatalogueProviderRefreshState::Processing, $retry->fresh()->state);
        $this->assertSame($currentId, $item->fresh()->current_catalogue_item_version_id);
        $this->assertSame(1, $item->versions()->count());
    }

    public function test_job_contract_is_bounded_unique_correlated_and_safe_on_final_failure(): void
    {
        $refresh = CatalogueProviderRefresh::factory()->create();
        $job = new RefreshOpenFoodFactsCatalogueItem($refresh->id, $refresh->correlation_id);

        $this->assertSame(3, $job->tries);
        $this->assertSame(60, $job->timeout);
        $this->assertSame([10, 60], $job->backoff());
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame($job->idempotencyFingerprint(), $job->uniqueId());
        $this->assertCount(1, $job->middleware());

        $job->failed(new RetryableJobException('provider_unavailable'));

        $this->assertSame(CatalogueProviderRefreshState::Failed, $refresh->fresh()->state);
        $this->assertSame('provider_unavailable', $refresh->fresh()->failure_code);
        $this->assertNull($refresh->fresh()->active_key);
    }

    private function importBaseItem(): CatalogueItem
    {
        $product = app(OpenFoodFactsProductMapper::class)->map($this->response());
        $mapped = app(OpenFoodFactsCatalogueMapper::class)->map($product);
        $result = app(CatalogueImportCreator::class)->createOrReuse(
            User::factory()->create(),
            '0012345678905',
            $mapped,
        );

        return $result->item->fresh(['currentVersion.nutrientObservations']);
    }

    /** @return array<string, mixed> */
    private function response(array $overrides = []): array
    {
        return [
            'status' => 'success',
            'result' => ['id' => 'product_found'],
            'product' => array_replace([
                'code' => '0012345678905',
                'product_name' => 'Base product',
                'keywords' => ['one'],
                'categories_tags' => ['en:test'],
                'quantity' => '4 cans x 400 g',
                'serving_quantity' => '200',
                'serving_size' => '200 g',
                'nutriments' => [
                    'energy-kcal_100g' => '100',
                    'proteins_100g' => '7.0',
                    'salt_100g' => '0.35',
                ],
                'image_front_url' => 'https://images.openfoodfacts.org/images/products/front.jpg',
            ], $overrides),
        ];
    }
}
