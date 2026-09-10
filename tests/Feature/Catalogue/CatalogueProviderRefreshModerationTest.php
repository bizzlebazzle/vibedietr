<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Catalogue\CatalogueChangeProposalType;
use App\Domain\Catalogue\CatalogueCorrectionModeration;
use App\Domain\Catalogue\CatalogueCorrectionProposalState;
use App\Domain\Catalogue\CatalogueItemSource;
use App\Domain\Catalogue\CatalogueModerationAuthorization;
use App\Domain\Catalogue\CatalogueProviderRefreshState;
use App\Domain\Nutrition\NutrientProvenance;
use App\Models\AuditEvent;
use App\Models\CatalogueCorrectionChange;
use App\Models\CatalogueCorrectionProposal;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\CatalogueProviderRefresh;
use App\Models\User;
use App\Security\SecondFactor\RecentAuthentication;
use App\Security\SecondFactor\SecondFactorEnrollmentService;
use App\Security\SecondFactor\TotpEngine;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CatalogueProviderRefreshModerationTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->administrator()->create(['password' => 'correct-password']);
        $enrollment = app(SecondFactorEnrollmentService::class);
        $presentation = $enrollment->begin($user, 'correct-password', app(RecentAuthentication::class), app('session.store'));
        $enrollment->confirm($user, app(TotpEngine::class)->codeAt($presentation->manualKey, app(TotpEngine::class)->currentTimestep()), '192.0.2.5');
        $enrollment->acknowledgeRecoveryCodes($user);
        $this->administrator = $user->fresh();
    }

    public function test_provider_review_acceptance_staleness_rejection_and_access_are_safe(): void
    {
        [$item, $base, $refresh, $proposal] = $this->staged('Old provider name', 'New provider name');
        $ordinary = User::factory()->create();

        $this->actingAs($ordinary)->get(route('admin.catalogue.provider-refresh', $refresh))->assertForbidden();
        $this->actingAs($this->administrator)
            ->get(route('admin.catalogue.index', ['type' => 'provider_refresh', 'state' => 'staged']))
            ->assertOk()->assertSee($refresh->id);
        $this->actingAs($this->administrator)->get(route('admin.catalogue.provider-refresh', $refresh))
            ->assertOk()->assertSee('Provider evidence')->assertSee('product_name');
        $this->assertSame($base->id, $item->fresh()->current_catalogue_item_version_id);
        $this->assertDatabaseCount('catalogue_item_versions', 1);

        $intervening = CatalogueItemVersion::factory()->for($item, 'catalogueItem')->completeNutrition()->create([
            'version_number' => 2,
            'name' => 'Old provider name',
            'manufacturer' => 'Later manufacturer',
        ]);
        $item->setCurrentVersion($intervening);
        $service = app(CatalogueCorrectionModeration::class);
        $this->assertTrue($service->review($proposal)->stale);
        try {
            $service->accept($proposal->id, $this->administrator, $this->proof());
            $this->fail('A stale provider proposal requires explicit review.');
        } catch (ValidationException) {
            $this->assertSame($intervening->id, $item->fresh()->current_catalogue_item_version_id);
        }

        $service->accept($proposal->id, $this->administrator, $this->proof(), true);
        $accepted = $item->fresh()->currentVersion;
        $this->assertSame(3, $accepted->version_number);
        $this->assertSame('New provider name', $accepted->name);
        $this->assertSame('Later manufacturer', $accepted->manufacturer);
        $this->assertNull($accepted->correction_proposal_id);
        $this->assertSame($refresh->id, $accepted->provider_refresh_id);
        $this->assertSame(['name', 'nutrition.protein.per_100g'], $accepted->refreshed_fields);
        $protein = $accepted->nutrientValues()->where('nutrient', 'protein')->sole();
        $this->assertSame('8.250000000000000000', $protein->value);
        $this->assertSame(NutrientProvenance::Imported, $protein->provenance);
        $this->assertSame($refresh->id, $protein->sourceObservation->provider_refresh_id);
        $this->assertSame('7.250000000000000000', $base->nutrientValues()->where('nutrient', 'protein')->sole()->value);
        $this->assertSame(CatalogueProviderRefreshState::Accepted, $refresh->fresh()->state);
        $this->assertNull($refresh->fresh()->active_key);
        $this->assertSame(CatalogueCorrectionProposalState::Accepted, $proposal->fresh()->state);
        $this->assertDatabaseHas('audit_events', ['action' => 'catalogue.provider_refresh_accepted']);

        $service->accept($proposal->id, $this->administrator, $this->proof(), true);
        $this->assertDatabaseCount('catalogue_item_versions', 3);
        $this->assertSame(1, AuditEvent::query()->where('action', 'catalogue.provider_refresh_accepted')->count());

        [$rejectedItem, $rejectedBase, $rejectedRefresh, $rejectedProposal] = $this->staged('Keep this name', 'Discard this name');
        $service->reject($rejectedProposal->id, $this->administrator, $this->proof(), note: 'Provider evidence was not sufficient.');
        $this->assertSame($rejectedBase->id, $rejectedItem->fresh()->current_catalogue_item_version_id);
        $this->assertSame(CatalogueProviderRefreshState::Rejected, $rejectedRefresh->fresh()->state);
        $this->assertNull($rejectedRefresh->fresh()->active_key);
        $this->assertDatabaseHas('audit_events', ['action' => 'catalogue.provider_refresh_rejected']);
    }

    /** @return array{CatalogueItem, CatalogueItemVersion, CatalogueProviderRefresh, CatalogueCorrectionProposal} */
    private function staged(string $beforeName, string $afterName): array
    {
        $barcode = fake()->unique()->numerify('0############');
        $item = CatalogueItem::factory()->barcodeBacked($barcode)->create(['source_identifier' => $barcode]);
        $base = CatalogueItemVersion::factory()->for($item, 'catalogueItem')->current()->completeNutrition()->create([
            'name' => $beforeName,
            'name_source' => CatalogueItemSource::OpenFoodFacts,
        ]);
        $refresh = CatalogueProviderRefresh::query()->forceCreate([
            'catalogue_item_id' => $item->id,
            'base_catalogue_item_version_id' => $base->id,
            'provider' => CatalogueItemSource::OpenFoodFacts,
            'source_identifier' => $barcode,
            'correlation_id' => strtolower((string) Str::ulid()),
            'state' => CatalogueProviderRefreshState::Staged,
            'active_key' => 'openfoodfacts:'.$item->id,
        ]);
        $proposal = CatalogueCorrectionProposal::query()->forceCreate([
            'proposal_type' => CatalogueChangeProposalType::ProviderRefresh,
            'catalogue_item_id' => $item->id,
            'base_catalogue_item_version_id' => $base->id,
            'provider_refresh_id' => $refresh->id,
            'reason' => 'OpenFoodFacts supplied material field changes.',
            'state' => CatalogueCorrectionProposalState::Pending,
            'submitted_at' => now()->utc(),
        ]);
        CatalogueCorrectionChange::query()->forceCreate([
            'proposal_id' => $proposal->id,
            'field_key' => 'name',
            'before_value' => ['value' => $beforeName],
            'proposed_value' => ['value' => $afterName],
            'provenance' => ['provider' => 'openfoodfacts', 'source_identifier' => $barcode, 'source_field' => 'product_name'],
        ]);
        CatalogueCorrectionChange::query()->forceCreate([
            'proposal_id' => $proposal->id,
            'field_key' => 'nutrition.protein.per_100g',
            'before_value' => ['value' => '7.25', 'threshold_value' => null, 'unit' => 'g', 'status' => 'known', 'source_scale' => 2],
            'proposed_value' => ['value' => '8.250', 'threshold_value' => null, 'unit' => 'g', 'status' => 'known', 'source_scale' => 3],
            'provenance' => ['provider' => 'openfoodfacts', 'source_identifier' => $barcode, 'source_field' => 'proteins_100g', 'observed_at' => now()->utc()->toIso8601String()],
        ]);

        return [$item->fresh(), $base, $refresh, $proposal->fresh()];
    }

    private function proof(): Session
    {
        $session = app('session.store');
        app(RecentAuthentication::class)->confirmPrimary($this->administrator, 'correct-password', $session);
        app(RecentAuthentication::class)->rememberFreshFactor($this->administrator, CatalogueModerationAuthorization::OPERATION, $session);

        return $session;
    }
}
