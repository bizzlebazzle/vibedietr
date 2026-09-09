<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Catalogue\CatalogueCandidateRecorder;
use App\Domain\Catalogue\CatalogueDuplicateEvidence;
use App\Domain\Catalogue\CatalogueItemStatus;
use App\Domain\Catalogue\CatalogueModeration;
use App\Domain\Catalogue\CatalogueModerationAuthorization;
use App\Domain\Catalogue\CatalogueReadQuery;
use App\Domain\Recipes\RecipeIngredientMatchManager;
use App\Domain\Recipes\RecipeLifecycle;
use App\Domain\Recipes\RecipeRevisionManager;
use App\Domain\Recipes\RecipeVersionContent;
use App\Models\AuditEvent;
use App\Models\CatalogueDuplicateCandidate;
use App\Models\CatalogueItem as Item;
use App\Models\CatalogueItemAlias;
use App\Models\CatalogueItemVersion;
use App\Models\CatalogueModerationDecision;
use App\Models\Recipe;
use App\Models\RecipeIngredientLineMatch;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Security\SecondFactor\RecentAuthentication;
use App\Security\SecondFactor\SecondFactorEnrollmentService;
use App\Security\SecondFactor\TotpEngine;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CatalogueModerationTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();
        config(['catalogue.read_cutover' => true]);
        $user = User::factory()->administrator()->create(['password' => 'correct-password']);
        $enrollment = app(SecondFactorEnrollmentService::class);
        $presentation = $enrollment->begin($user, 'correct-password', app(RecentAuthentication::class), app('session.store'));
        $enrollment->confirm($user, app(TotpEngine::class)->codeAt($presentation->manualKey, app(TotpEngine::class)->currentTimestep()), '192.0.2.5');
        $enrollment->acknowledgeRecoveryCodes($user);
        $this->administrator = $user->fresh();
    }

    public function test_approval_preserves_identity_version_and_provenance_and_stale_rejection_conflicts(): void
    {
        $item = $this->item('Pending food', false);
        $before = $item->currentVersion->getAttributes();
        $review = $this->review($item);
        app(CatalogueModeration::class)->approvePending($item->id, $this->administrator, $this->proof(), $review);
        $this->assertSame(CatalogueItemStatus::Approved, $item->fresh()->status);
        $this->assertSame($before, $item->fresh()->currentVersion->getAttributes());
        $this->get(route('catalogue.show', $item))->assertOk();
        $this->expectException(ValidationException::class);
        app(CatalogueModeration::class)->rejectPending($item->id, $this->administrator, $this->proof(), $review);
    }

    public function test_rejection_preserves_matches_and_suggestion_resolves_after_later_merge(): void
    {
        $pending = $this->item('Private rejected food', false);
        $b = $this->item('Replacement B');
        $c = $this->item('Replacement C');
        $recipe = Recipe::factory()->validDraft()->create(['user_id' => $pending->submitted_by_user_id]);
        $owner = User::query()->findOrFail($recipe->user_id);
        $line = $recipe->ingredientLines()->first();
        $match = app(RecipeIngredientMatchManager::class)->select($recipe->id, $line->id, $pending->id, $pending->current_catalogue_item_version_id, $owner);
        app(CatalogueModeration::class)->rejectPending($pending->id, $this->administrator, $this->proof(), $this->review($pending) + ['note' => 'Private review'], $b->id);
        $this->assertSame($pending->current_catalogue_item_version_id, $match->fresh()->catalogue_item_version_id);
        $this->merge($b, $c);
        $this->assertSame($b->id, $pending->fresh()->suggested_replacement_catalogue_item_id);
        $read = app(CatalogueReadQuery::class);
        $projection = $read->project($read->findVisibleOrFail($pending->id, $owner));
        $this->assertSame($c->id, $projection->suggestedReplacement['id']);
        app(RecipeIngredientMatchManager::class)->confirmRejectedReplacement($recipe->id, $line->id, $owner);
        $this->assertSame($c->current_catalogue_item_version_id, $match->fresh()->catalogue_item_version_id);
        $this->get(route('catalogue.show', $pending))->assertNotFound();
    }

    public function test_merge_moves_only_editable_matches_and_correction_retains_history(): void
    {
        $source = $this->item('Old approved name');
        $canonical = $this->item('Canonical food');
        $draft = Recipe::factory()->validDraft()->create();
        $published = Recipe::factory()->validDraft()->create();
        $draftMatch = $this->match($draft, $source);
        $publishedMatch = $this->match($published, $source);
        $this->publishFixture($published);
        $snapshot = $published->fresh()->currentVersion->getAttributes();
        $sourceVersion = $source->currentVersion->getAttributes();
        $merge = $this->merge($source, $canonical);
        $this->assertSame($canonical->current_catalogue_item_version_id, $draftMatch->fresh()->catalogue_item_version_id);
        $this->assertSame($source->current_catalogue_item_version_id, $publishedMatch->fresh()->catalogue_item_version_id);
        $this->assertSame($snapshot, $published->fresh()->currentVersion->getAttributes());
        $this->assertSame($sourceVersion, $source->fresh()->currentVersion->getAttributes());
        $this->assertDatabaseCount('catalogue_reference_moves', 1);
        $this->assertSame($canonical->id, app(CatalogueReadQuery::class)->findVisibleOrFail($source->id, null)->id);
        $results = app(CatalogueReadQuery::class)->paginate(null, 'Old approved name');
        $this->assertSame([$canonical->id], collect($results->items())->pluck('id')->all());
        $original = $merge->fresh()->getAttributes();
        $correction = app(CatalogueModeration::class)->correctDecision($merge->id, $this->administrator, $this->proof(), $this->correction());
        $this->assertSame($merge->id, $correction->corrects_decision_id);
        $this->assertSame($original, $merge->fresh()->getAttributes());
        $this->assertSame($source->current_catalogue_item_version_id, $draftMatch->fresh()->catalogue_item_version_id);
        $this->assertSame(CatalogueItemStatus::Approved, $source->fresh()->status);
        $this->assertNull($source->fresh()->canonical_catalogue_item_id);
        $this->assertSame($snapshot, $published->fresh()->currentVersion->getAttributes());
    }

    public function test_later_same_version_owner_choice_requires_review_and_is_not_overwritten(): void
    {
        $a = $this->item('A');
        $b = $this->item('B');
        $recipe = Recipe::factory()->validDraft()->create();
        $match = $this->match($recipe, $a);
        $merge = $this->merge($a, $b);
        $this->match($recipe, $b);
        try {
            app(CatalogueModeration::class)->correctDecision($merge->id, $this->administrator, $this->proof(), $this->correction());
            $this->fail('Later owner selection must require review.');
        } catch (ValidationException) {
            $this->assertSame(CatalogueItemStatus::Merged, $a->fresh()->status);
        }
        $correction = app(CatalogueModeration::class)->correctDecision($merge->id, $this->administrator, $this->proof(), $this->correction() + ['preserve_later_choices' => true]);
        $this->assertSame(1, $correction->evidence['preserved_count']);
        $this->assertSame($b->current_catalogue_item_version_id, $match->fresh()->catalogue_item_version_id);
    }

    public function test_subsequent_merge_flattens_redirects_and_blocks_unsafe_old_reversal(): void
    {
        $a = $this->item('A');
        $b = $this->item('B');
        $c = $this->item('C');
        $original = $this->merge($a, $b);
        $this->merge($b->fresh(), $c);
        $this->assertSame($c->id, $a->fresh()->canonical_catalogue_item_id);
        $this->assertSame($c->id, $b->fresh()->canonical_catalogue_item_id);
        $this->expectException(ValidationException::class);
        app(CatalogueModeration::class)->correctDecision($original->id, $this->administrator, $this->proof(), $this->correction());
    }

    public function test_distinct_and_dismissed_do_not_change_identity_and_correction_reopens_pair(): void
    {
        $a = $this->item('A');
        $b = $this->item('B');
        $candidate = $this->candidate($a, $b);
        $before = $a->getAttributes();
        $decision = app(CatalogueModeration::class)->markCandidateDistinct($candidate->id, $this->administrator, $this->proof(), $this->pairReview($candidate));
        $this->assertSame($before, $a->fresh()->getAttributes());
        $this->assertSame('confirmed_distinct', $candidate->fresh()->status->value);
        app(CatalogueModeration::class)->correctDecision($decision->id, $this->administrator, $this->proof(), $this->correction());
        app(CatalogueModeration::class)->dismissCandidate($candidate->id, $this->administrator, $this->proof(), $this->pairReview($candidate->fresh()));
        $this->assertSame('dismissed', $candidate->fresh()->status->value);
        $this->assertSame($before, $a->fresh()->getAttributes());
    }

    public function test_confirmation_requires_explicit_canonical_pair_member_and_approved_items(): void
    {
        $a = $this->item('A');
        $b = $this->item('B');
        $candidate = $this->candidate($a, $b);
        $this->expectException(ValidationException::class);
        app(CatalogueModeration::class)->confirmDuplicate($candidate->id, 0, $this->administrator, $this->proof(), $this->pairReview($candidate));
    }

    public function test_stale_candidate_decision_cannot_override_distinct_outcome(): void
    {
        $a = $this->item('A');
        $b = $this->item('B');
        $candidate = $this->candidate($a, $b);
        $review = $this->pairReview($candidate);
        app(CatalogueModeration::class)->markCandidateDistinct($candidate->id, $this->administrator, $this->proof(), $review);
        $this->expectException(ValidationException::class);
        app(CatalogueModeration::class)->confirmDuplicate($candidate->id, $b->id, $this->administrator, $this->proof(), $review);
    }

    public function test_ordinary_user_cannot_call_mutation_service_directly(): void
    {
        $item = $this->item('Pending', false);
        $this->expectException(AuthorizationException::class);
        app(CatalogueModeration::class)->approvePending($item->id, User::factory()->create(), app('session.store'), $this->review($item));
    }

    public function test_consumed_proof_cannot_authorize_another_decision(): void
    {
        $first = $this->item('One', false);
        $second = $this->item('Two', false);
        $session = $this->proof();
        app(CatalogueModeration::class)->approvePending($first->id, $this->administrator, $session, $this->review($first));
        $this->expectException(HttpException::class);
        app(CatalogueModeration::class)->approvePending($second->id, $this->administrator, $session, $this->review($second));
    }

    public function test_new_revision_resolves_merged_match_without_rewriting_published_snapshot(): void
    {
        $a = $this->item('A');
        $b = $this->item('B');
        $recipe = Recipe::factory()->validDraft()->create();
        $this->match($recipe, $a);
        $this->publishFixture($recipe);
        $snapshot = $recipe->fresh()->currentVersion->getAttributes();
        $this->merge($a, $b);
        app(RecipeRevisionManager::class)->startOrResume($recipe->id, User::query()->findOrFail($recipe->user_id));
        $this->assertSame($b->current_catalogue_item_version_id, $recipe->fresh()->ingredientLines->first()->catalogueMatch->catalogue_item_version_id);
        $this->assertSame($snapshot, $recipe->fresh()->currentVersion->getAttributes());
    }

    public function test_reverse_canonical_choice_works_and_repeated_merge_is_a_safe_conflict(): void
    {
        $older = $this->item('Older');
        $newer = $this->item('Newer');
        $merge = $this->merge($newer, $older);
        $this->assertSame($older->id, $newer->fresh()->canonical_catalogue_item_id);
        $candidate = CatalogueDuplicateCandidate::query()->findOrFail($merge->candidate_id);
        $this->expectException(ValidationException::class);
        app(CatalogueModeration::class)->mergeApproved($candidate->id, $older->id, $this->administrator, $this->proof(), $this->pairReview($candidate));
    }

    public function test_pending_pair_cannot_be_confirmed_for_merge(): void
    {
        $pending = $this->item('Pending', false);
        $approved = $this->item('Approved');
        $candidate = $this->candidate($pending, $approved);
        $this->expectException(ValidationException::class);
        app(CatalogueModeration::class)->confirmDuplicate($candidate->id, $approved->id, $this->administrator, $this->proof(), $this->pairReview($candidate));
    }

    public function test_core_contradiction_denies_duplicate_even_with_moderator_attestation(): void
    {
        $a = $this->item('A');
        $b = $this->item('B');
        $a->currentVersion->forceFill(['food_form' => 'dried'])->save();
        $b->currentVersion->forceFill(['food_form' => 'fresh'])->save();
        $this->expectException(ValidationException::class);
        $this->merge($a, $b);
    }

    public function test_merge_requires_same_versions_as_confirmation_and_explicit_confirmation(): void
    {
        $a = $this->item('A');
        $b = $this->item('B');
        $candidate = $this->candidate($a, $b);
        $service = app(CatalogueModeration::class);
        $service->confirmDuplicate($candidate->id, $b->id, $this->administrator, $this->proof(), $this->pairReview($candidate));
        CatalogueItemVersion::factory()->current()->create(['catalogue_item_id' => $a->id, 'version_number' => 2, 'name' => 'New facts']);
        $this->expectException(ValidationException::class);
        $service->mergeApproved($candidate->id, $b->id, $this->administrator, $this->proof(), $this->pairReview($candidate->fresh()));
    }

    public function test_primary_alias_exclusion_and_explicit_other_alias_approval(): void
    {
        $a = $this->item('Excluded primary name');
        $b = $this->item('Canonical');
        $alias = CatalogueItemAlias::query()->forceCreate(['catalogue_item_id' => $a->id, 'alias' => 'Approved alternative', 'approved_at' => now()]);
        CatalogueItemAlias::query()->forceCreate(['catalogue_item_id' => $a->id, 'alias' => 'Not transferred', 'approved_at' => now()]);
        $candidate = $this->candidate($a, $b);
        $service = app(CatalogueModeration::class);
        $service->confirmDuplicate($candidate->id, $b->id, $this->administrator, $this->proof(), $this->pairReview($candidate));
        $service->mergeApproved($candidate->id, $b->id, $this->administrator, $this->proof(), $this->pairReview($candidate->fresh()) + ['exclude_primary_alias' => true, 'alias_ids' => [$alias->id]]);
        $this->assertDatabaseHas('catalogue_item_aliases', ['catalogue_item_id' => $b->id, 'alias' => 'Approved alternative']);
        $this->assertDatabaseMissing('catalogue_item_aliases', ['catalogue_item_id' => $b->id, 'alias' => 'Excluded primary name']);
        $this->assertDatabaseMissing('catalogue_item_aliases', ['catalogue_item_id' => $b->id, 'alias' => 'Not transferred']);
    }

    public function test_audit_failure_rolls_back_decision_and_lifecycle(): void
    {
        $item = $this->item('Pending', false);
        AuditEvent::creating(function () {
            throw new \RuntimeException('Synthetic persistence failure');
        });
        try {
            app(CatalogueModeration::class)->approvePending($item->id, $this->administrator, $this->proof(), $this->review($item));
            $this->fail('Audit failure must abort approval.');
        } catch (\RuntimeException) {
            $this->assertSame(CatalogueItemStatus::Pending, $item->fresh()->status);
            $this->assertDatabaseCount('catalogue_moderation_decisions', 0);
        }
    }

    public function test_rejection_correction_appends_history_and_retains_original_recommendation(): void
    {
        $item = $this->item('Pending', false);
        $replacement = $this->item('Replacement');
        $decision = app(CatalogueModeration::class)->rejectPending($item->id, $this->administrator, $this->proof(), $this->review($item), $replacement->id);
        app(CatalogueModeration::class)->correctDecision($decision->id, $this->administrator, $this->proof(), $this->correction());
        $this->assertSame(CatalogueItemStatus::Pending, $item->fresh()->status);
        $this->assertSame($replacement->id, $item->fresh()->suggested_replacement_catalogue_item_id);
        $this->assertDatabaseCount('catalogue_moderation_decisions', 2);
    }

    public function test_moderator_note_is_private_and_excluded_from_audit_payload(): void
    {
        $item = $this->item('Pending', false);
        $decision = app(CatalogueModeration::class)->approvePending($item->id, $this->administrator, $this->proof(), $this->review($item) + ['note' => 'Private moderation detail']);
        $this->assertSame('Private moderation detail', $decision->note);
        $this->assertArrayNotHasKey('note', $decision->toArray());
        $this->assertStringNotContainsString('Private moderation detail', AuditEvent::query()->get()->toJson());
    }

    private function publishFixture(Recipe $recipe): void
    {
        $recipe->load(['ingredientLines', 'instructionSections', 'instructionSteps']);
        $version = RecipeVersion::factory()->create([
            'recipe_id' => $recipe->id,
            'snapshot' => app(RecipeVersionContent::class)->snapshot($recipe),
        ]);
        $recipe->forceFill(['lifecycle' => RecipeLifecycle::Finalized,
            'current_recipe_version_id' => $version->id, 'finalized_at' => now()])->save();
    }

    private function item(string $name, bool $approved = true): Item
    {
        $item = Item::factory()->submittedBy()->create(['status' => $approved ? CatalogueItemStatus::Approved : CatalogueItemStatus::Pending]);
        CatalogueItemVersion::factory()->current()->create(['catalogue_item_id' => $item->id, 'name' => $name, 'normalized_name' => strtolower($name)]);

        return $item->fresh();
    }

    private function proof(): Session
    {
        $session = app('session.store');
        app(RecentAuthentication::class)->confirmPrimary($this->administrator, 'correct-password', $session);
        app(RecentAuthentication::class)->rememberFreshFactor($this->administrator, CatalogueModerationAuthorization::OPERATION, $session);

        return $session;
    }

    /** @return array<string, mixed> */
    private function review(Item $item): array
    {
        return ['revision' => $item->moderation_revision, 'version_id' => $item->current_catalogue_item_version_id, 'reason_code' => 'reviewed'];
    }

    /** @return array<string, mixed> */
    private function pairReview(CatalogueDuplicateCandidate $candidate): array
    {
        return ['revision' => $candidate->moderation_revision, 'first_version_id' => $candidate->firstItem->current_catalogue_item_version_id,
            'second_version_id' => $candidate->secondItem->current_catalogue_item_version_id, 'reason_code' => 'duplicate', 'identity_reviewed' => true, 'confirm_merge' => true];
    }

    /** @return array<string, mixed> */
    private function correction(): array
    {
        return ['reason_code' => 'incorrect_decision', 'confirm_correction' => true];
    }

    private function candidate(Item $a, Item $b): CatalogueDuplicateCandidate
    {
        return app(CatalogueCandidateRecorder::class)->record($a->id, $b->id, CatalogueDuplicateEvidence::ExactPrimaryName);
    }

    private function merge(Item $source, Item $canonical): CatalogueModerationDecision
    {
        $candidate = $this->candidate($source, $canonical);
        $service = app(CatalogueModeration::class);
        $service->confirmDuplicate($candidate->id, $canonical->id, $this->administrator, $this->proof(), $this->pairReview($candidate));

        return $service->mergeApproved($candidate->id, $canonical->id, $this->administrator, $this->proof(), $this->pairReview($candidate->fresh()));
    }

    private function match(Recipe $recipe, Item $item): RecipeIngredientLineMatch
    {
        return app(RecipeIngredientMatchManager::class)->select($recipe->id, $recipe->ingredientLines()->first()->id, $item->id, $item->current_catalogue_item_version_id, User::query()->findOrFail($recipe->user_id));
    }
}
