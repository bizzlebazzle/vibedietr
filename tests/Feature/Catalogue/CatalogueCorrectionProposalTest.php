<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Catalogue\CatalogueCorrectionModeration;
use App\Domain\Catalogue\CatalogueCorrectionProposalCreator;
use App\Domain\Catalogue\CatalogueCorrectionProposalState;
use App\Domain\Catalogue\CatalogueItemStatus;
use App\Domain\Catalogue\CatalogueModerationAuthorization;
use App\Domain\Nutrition\NutrientProvenance;
use App\Models\AuditEvent;
use App\Models\CatalogueCorrectionChange;
use App\Models\CatalogueCorrectionProposal;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\User;
use App\Security\SecondFactor\RecentAuthentication;
use App\Security\SecondFactor\SecondFactorEnrollmentService;
use App\Security\SecondFactor\TotpEngine;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CatalogueCorrectionProposalTest extends TestCase
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

    public function test_authenticated_form_and_private_admin_queue_use_existing_authorization_boundaries(): void
    {
        config(['catalogue.read_cutover' => true]);
        $user = User::factory()->create();
        [$item, $base] = $this->item();

        $this->get(route('catalogue.corrections.create', $item))->assertRedirect(route('login'));
        $this->actingAs($user)->get(route('catalogue.corrections.create', $item))
            ->assertOk()->assertSee('Suggest a catalogue correction')->assertDontSee('barcode', false);
        $this->actingAs($user)->post(route('catalogue.corrections.store', $item), [
            'base_version_id' => $base->id,
            'reason' => 'The public name is inaccurate.',
            'change' => ['name' => '1'],
            'name' => 'Web corrected food',
        ])->assertRedirect(route('catalogue.show', $item));

        $proposal = CatalogueCorrectionProposal::query()->sole();
        $this->actingAs($user)->get(route('catalogue.show', $item))
            ->assertOk()->assertSee('Original food')->assertDontSee($proposal->id)->assertDontSee($proposal->reason);
        $this->actingAs($user)->get(route('admin.catalogue.index', ['type' => 'correction_proposal']))->assertForbidden();

        $this->actingAs($this->administrator)
            ->get(route('admin.catalogue.index', ['type' => 'correction_proposal', 'state' => 'pending']))
            ->assertOk()->assertSee($proposal->id);
        $this->actingAs($this->administrator)->get(route('admin.catalogue.correction', $proposal))
            ->assertOk()->assertSee('The public name is inaccurate.')->assertSee('Base')->assertSee('Current')->assertSee('Proposed');
    }

    public function test_authenticated_user_creates_immutable_bounded_proposal_without_mutating_catalogue(): void
    {
        $user = User::factory()->create();
        [$item, $base] = $this->item();
        $proposal = app(CatalogueCorrectionProposalCreator::class)->create($user, $item->id, $base->id, '  Protein panel is wrong.  ', [
            'name' => 'Corrected food',
            'package' => [
                'package_count' => 2,
                'item_type' => 'can',
                'amount_per_item' => '250',
                'amount_per_item_unit' => 'gram',
                'servings_per_item' => '2',
            ],
            'nutrition.protein.per_100g' => ['value' => '8.250', 'unit' => 'g', 'status' => 'known'],
            'nutrition.sugars.per_100g' => ['value' => '0', 'unit' => 'g', 'status' => 'known'],
        ]);

        $this->assertSame(CatalogueCorrectionProposalState::Pending, $proposal->state);
        $this->assertSame($item->id, $proposal->catalogue_item_id);
        $this->assertSame($base->id, $proposal->base_catalogue_item_version_id);
        $this->assertSame($user->id, $proposal->proposer_user_id);
        $this->assertSame('Protein panel is wrong.', $proposal->reason);
        $this->assertCount(4, $proposal->changes);
        $protein = $proposal->changes->firstWhere('field_key', 'nutrition.protein.per_100g');
        $this->assertInstanceOf(CatalogueCorrectionChange::class, $protein);
        $this->assertSame('7.25', $protein->before_value['value']);
        $this->assertSame('8.250', $protein->proposed_value['value']);
        $this->assertSame(3, $protein->proposed_value['source_scale']);
        $sugars = $proposal->changes->firstWhere('field_key', 'nutrition.sugars.per_100g');
        $this->assertInstanceOf(CatalogueCorrectionChange::class, $sugars);
        $this->assertSame('0', $sugars->proposed_value['value']);
        $this->assertSame($base->id, $item->fresh()->current_catalogue_item_version_id);
        $this->assertDatabaseCount('catalogue_item_versions', 1);
        $event = AuditEvent::query()->where('action', 'catalogue.correction_proposed')->sole();
        $this->assertSame('pending', $event->payload['outcome']);
        $this->assertStringNotContainsString('Protein panel', json_encode($event->payload, JSON_THROW_ON_ERROR));
    }

    public function test_validation_rejects_cross_item_base_protected_noop_invalid_and_ineligible_changes(): void
    {
        $user = User::factory()->create();
        [$item, $base] = $this->item();
        [, $foreign] = $this->item('Other');
        $creator = app(CatalogueCorrectionProposalCreator::class);

        foreach ([
            fn () => $creator->create($user, $item->id, $foreign->id, 'Wrong.', ['name' => 'New']),
            fn () => $creator->create($user, $item->id, $base->id, 'Wrong.', ['barcode' => '999']),
            fn () => $creator->create($user, $item->id, $base->id, 'Wrong.', ['name' => 'Original food']),
            fn () => $creator->create($user, $item->id, $base->id, 'Wrong.', ['nutrition.protein.per_100g' => ['value' => '-1', 'unit' => 'g', 'status' => 'known']]),
        ] as $invalid) {
            try {
                $invalid();
                $this->fail('Invalid correction should fail.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $item->forceFill(['status' => CatalogueItemStatus::Rejected])->save();
        $this->expectException(ValidationException::class);
        $creator->create($user, $item->id, $base->id, 'Wrong.', ['name' => 'New']);
    }

    public function test_non_stale_acceptance_creates_one_new_version_with_corrected_provenance(): void
    {
        $user = User::factory()->create();
        [$item, $base] = $this->item();
        $proposal = app(CatalogueCorrectionProposalCreator::class)->create($user, $item->id, $base->id, 'Panel correction.', [
            'name' => 'Corrected food',
            'nutrition.protein.per_100g' => ['value' => '8.250', 'unit' => 'g', 'status' => 'known'],
        ]);

        $decision = app(CatalogueCorrectionModeration::class)->accept($proposal->id, $this->administrator, $this->proof());
        $current = $item->fresh()->currentVersion;
        $this->assertNotSame($base->id, $current->id);
        $this->assertSame(2, $current->version_number);
        $this->assertSame('Corrected food', $current->name);
        $this->assertSame($proposal->id, $current->correction_proposal_id);
        $this->assertSame($decision->id, $current->correction_decision_id);
        $this->assertSame(['name', 'nutrition.protein.per_100g'], $current->corrected_fields);
        $protein = $current->nutrientValues()->where('nutrient', 'protein')->sole();
        $this->assertSame('8.250000000000000000', $protein->value);
        $this->assertSame(NutrientProvenance::Corrected, $protein->provenance);
        $this->assertSame($proposal->id, $protein->sourceObservation->correction_proposal_id);
        $this->assertSame('7.250000000000000000', $base->nutrientValues()->where('nutrient', 'protein')->sole()->value);
        $this->assertSame(CatalogueCorrectionProposalState::Accepted, $proposal->fresh()->state);

        app(CatalogueCorrectionModeration::class)->accept($proposal->id, $this->administrator, $this->proof());
        $this->assertDatabaseCount('catalogue_item_versions', 2);
        $this->assertDatabaseCount('catalogue_moderation_decisions', 1);
        $this->assertDatabaseCount('audit_events', 2);
    }

    public function test_stale_review_is_server_enforced_and_carries_forward_unrelated_current_changes(): void
    {
        $user = User::factory()->create();
        [$item, $base] = $this->item();
        $proposal = app(CatalogueCorrectionProposalCreator::class)->create($user, $item->id, $base->id, 'Protein correction.', [
            'nutrition.protein.per_100g' => ['value' => '9', 'unit' => 'g', 'status' => 'known'],
        ]);
        $current = CatalogueItemVersion::factory()->incompleteNutrition()->create([
            'catalogue_item_id' => $item->id,
            'version_number' => 2,
            'name' => $base->name,
            'manufacturer' => 'New manufacturer',
        ]);
        $item->setCurrentVersion($current);

        $review = app(CatalogueCorrectionModeration::class)->review($proposal);
        $this->assertTrue($review->stale);
        $this->assertSame([], $review->conflicts());
        try {
            app(CatalogueCorrectionModeration::class)->accept($proposal->id, $this->administrator, $this->proof());
            $this->fail('Blind stale acceptance should fail.');
        } catch (ValidationException) {
            $this->assertSame($current->id, $item->fresh()->current_catalogue_item_version_id);
        }

        app(CatalogueCorrectionModeration::class)->accept($proposal->id, $this->administrator, $this->proof(), true);
        $accepted = $item->fresh()->currentVersion;
        $this->assertSame('New manufacturer', $accepted->manufacturer);
        $this->assertSame('9.000000000000000000', $accepted->nutrientValues()->where('nutrient', 'protein')->sole()->value);
        $this->assertSame($base->id, $proposal->fresh()->base_catalogue_item_version_id);
    }

    public function test_same_field_stale_change_is_reported_as_conflict(): void
    {
        $user = User::factory()->create();
        [$item, $base] = $this->item();
        $proposal = app(CatalogueCorrectionProposalCreator::class)->create($user, $item->id, $base->id, 'Name correction.', ['name' => 'Proposed name']);
        $current = CatalogueItemVersion::factory()->create(['catalogue_item_id' => $item->id, 'version_number' => 2, 'name' => 'Different current name']);
        $item->setCurrentVersion($current);

        $review = app(CatalogueCorrectionModeration::class)->review($proposal);
        $this->assertSame(['name'], $review->conflicts());
        $this->assertSame(['value' => 'Original food'], $review->changes[0]['before']);
        $this->assertSame(['value' => 'Different current name'], $review->changes[0]['current']);
        $this->assertSame(['value' => 'Proposed name'], $review->changes[0]['proposed']);
    }

    public function test_rejection_and_proposer_deletion_preserve_evidence_and_current_version(): void
    {
        $user = User::factory()->create();
        [$item, $base] = $this->item();
        $proposal = app(CatalogueCorrectionProposalCreator::class)->create($user, $item->id, $base->id, 'Private reason.', ['brand' => null]);
        $user->delete();
        $this->assertNull($proposal->fresh()->proposer_user_id);

        app(CatalogueCorrectionModeration::class)->reject($proposal->id, $this->administrator, $this->proof(), note: 'Private moderator note.');
        $this->assertSame(CatalogueCorrectionProposalState::Rejected, $proposal->fresh()->state);
        $this->assertSame('Private reason.', $proposal->fresh()->reason);
        $this->assertSame($base->id, $item->fresh()->current_catalogue_item_version_id);
        $this->assertDatabaseCount('catalogue_item_versions', 1);
        $event = AuditEvent::query()->where('action', 'catalogue.correction_rejected')->sole();
        $this->assertStringNotContainsString('Private', json_encode($event->payload, JSON_THROW_ON_ERROR));
    }

    /** @return array{CatalogueItem, CatalogueItemVersion} */
    private function item(string $name = 'Original food'): array
    {
        $item = CatalogueItem::factory()->approved()->create();
        $version = CatalogueItemVersion::factory()->current()->incompleteNutrition()->create([
            'catalogue_item_id' => $item->id,
            'version_number' => 1,
            'name' => $name,
            'brand' => 'Original brand',
        ]);

        return [$item->fresh(), $version];
    }

    private function proof(): Session
    {
        $session = app('session.store');
        app(RecentAuthentication::class)->confirmPrimary($this->administrator, 'correct-password', $session);
        app(RecentAuthentication::class)->rememberFreshFactor($this->administrator, CatalogueModerationAuthorization::OPERATION, $session);

        return $session;
    }
}
