<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Catalogue\CatalogueChangeProposalType;
use App\Domain\Catalogue\CatalogueItemSource;
use App\Domain\Catalogue\CatalogueProviderRefreshState;
use App\Models\CatalogueCorrectionProposal;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\CatalogueProviderRefresh;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class CatalogueProviderRefreshFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_run_pins_safe_identity_base_and_correlation_metadata(): void
    {
        [$item, $version] = $this->openFoodFactsItem();

        $refresh = CatalogueProviderRefresh::query()->forceCreate([
            'catalogue_item_id' => $item->id,
            'base_catalogue_item_version_id' => $version->id,
            'provider' => CatalogueItemSource::OpenFoodFacts,
            'source_identifier' => $item->source_identifier,
            'correlation_id' => strtolower((string) Str::ulid()),
            'state' => CatalogueProviderRefreshState::Queued,
            'active_key' => 'openfoodfacts:'.$item->id,
        ]);

        $this->assertSame($version->id, $refresh->baseVersion->id);
        $this->assertSame($item->id, $refresh->catalogueItem->id);
        $this->assertSame(CatalogueProviderRefreshState::Queued, $refresh->state);
        $this->assertFalse($refresh->state->isTerminal());
    }

    public function test_only_one_active_run_key_can_exist_but_terminal_history_can_repeat(): void
    {
        [$item, $version] = $this->openFoodFactsItem();
        $attributes = [
            'catalogue_item_id' => $item->id,
            'base_catalogue_item_version_id' => $version->id,
            'provider' => CatalogueItemSource::OpenFoodFacts,
            'source_identifier' => $item->source_identifier,
            'correlation_id' => strtolower((string) Str::ulid()),
            'state' => CatalogueProviderRefreshState::Queued,
            'active_key' => 'openfoodfacts:'.$item->id,
        ];
        CatalogueProviderRefresh::query()->forceCreate($attributes);

        try {
            CatalogueProviderRefresh::query()->forceCreate([
                ...$attributes,
                'correlation_id' => strtolower((string) Str::ulid()),
            ]);
            $this->fail('The database accepted a duplicate active refresh key.');
        } catch (QueryException) {
            $this->assertDatabaseCount('catalogue_provider_refreshes', 1);
        }

        CatalogueProviderRefresh::query()->firstOrFail()->forceFill([
            'state' => CatalogueProviderRefreshState::NoChange,
            'active_key' => null,
            'completed_at' => now()->utc(),
        ])->save();

        CatalogueProviderRefresh::query()->forceCreate([
            ...$attributes,
            'correlation_id' => strtolower((string) Str::ulid()),
        ]);

        $this->assertDatabaseCount('catalogue_provider_refreshes', 2);
    }

    public function test_refresh_identity_evidence_is_immutable(): void
    {
        $refresh = CatalogueProviderRefresh::factory()->create();

        $this->expectException(LogicException::class);
        $refresh->forceFill(['source_identifier' => 'different'])->save();
    }

    public function test_existing_correction_factory_is_explicitly_user_authored(): void
    {
        $proposal = CatalogueCorrectionProposal::factory()->create();

        $this->assertSame(CatalogueChangeProposalType::UserCorrection, $proposal->proposal_type);
        $this->assertNull($proposal->provider_refresh_id);
    }

    /** @return array{CatalogueItem, CatalogueItemVersion} */
    private function openFoodFactsItem(): array
    {
        $barcode = '0123456789012';
        $item = CatalogueItem::factory()->barcodeBacked($barcode)->create([
            'source_identifier' => $barcode,
        ]);
        $version = CatalogueItemVersion::factory()->for($item, 'catalogueItem')->current()->create([
            'name' => 'Provider food',
            'name_source' => CatalogueItemSource::OpenFoodFacts,
        ]);

        return [$item->fresh(), $version];
    }
}
