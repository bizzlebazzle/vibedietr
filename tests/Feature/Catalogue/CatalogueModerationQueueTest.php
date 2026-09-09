<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Catalogue\CatalogueCandidateRecorder;
use App\Domain\Catalogue\CatalogueDuplicateEvidence;
use App\Domain\Catalogue\CatalogueModerationQueue;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\CatalogueModerationDecision;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogueModerationQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_and_ordinary_user_cannot_read_queue_or_private_details(): void
    {
        $item = CatalogueItem::factory()->create();
        $this->get(route('admin.catalogue.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('admin.catalogue.index'))->assertForbidden();
        $this->get(route('admin.catalogue.submission', $item))->assertForbidden();
        $this->post(route('admin.catalogue.approve', $item))->assertForbidden();
    }

    public function test_administrator_browses_filtered_paginated_queue_with_matching_totals(): void
    {
        $administrator = User::factory()->administrator()->create();
        CatalogueItem::factory()->count(27)->create();
        CatalogueItem::factory()->approved()->count(3)->create();
        CatalogueItem::factory()->barcodeBacked()->create();
        $queue = app(CatalogueModerationQueue::class);
        $first = $queue->paginate($administrator, 'manual_submission', 'pending');
        $this->assertSame(27, $first->total());
        $this->assertCount(25, $first->items());
        $this->actingAs($administrator)->get(route('admin.catalogue.index', ['type' => 'manual_submission', 'state' => 'pending', 'page' => 2]))
            ->assertOk()->assertSee('27 matching records')->assertViewHas('rows', fn ($rows) => count($rows->items()) === 2 && $rows->firstItem() === 26);
        $this->get(route('admin.catalogue.index', ['type' => 'manual_submission', 'state' => 'confirmed_distinct']))->assertSessionHasErrors('state');
        $this->assertSame(3, $queue->paginate($administrator, 'manual_submission', 'approved')->total());
    }

    public function test_candidate_detail_renders_explicit_unselected_canonical_choices_and_private_evidence(): void
    {
        $a = $this->item('First food');
        $b = $this->item('Second food');
        $candidate = app(CatalogueCandidateRecorder::class)->record($a->id, $b->id, CatalogueDuplicateEvidence::ExactPrimaryName, explanation: 'Private distinction detail');
        $response = $this->actingAs(User::factory()->administrator()->create())->get(route('admin.catalogue.candidate', $candidate));
        $response->assertOk()->assertSee('First food')->assertSee('Second food')->assertSee('Private distinction detail')
            ->assertSee('Explicitly choose the canonical identity')->assertSee('name="canonical_id"', false)->assertDontSee('checked', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get(route('admin.catalogue.index', ['type' => 'duplicate_candidate', 'state' => 'pending_review']))->assertOk()->assertSee('1 matching record');
    }

    public function test_pending_inspection_and_decision_history_render_but_no_factor_cannot_mutate(): void
    {
        $item = $this->item('Submission');
        $administrator = User::factory()->administrator()->create();
        $this->actingAs($administrator)->get(route('admin.catalogue.submission', $item))->assertOk()->assertSee('Approve submission')->assertSee('Reject submission');
        $this->post(route('admin.catalogue.approve', $item), ['revision' => 0, 'version_id' => $item->current_catalogue_item_version_id, 'reason_code' => 'reviewed'])->assertForbidden();
        $decision = CatalogueModerationDecision::query()->forceCreate(['action' => 'reject', 'catalogue_item_id' => $item->id, 'reason_code' => 'reviewed', 'evidence' => [], 'note' => 'Private note']);
        $this->get(route('admin.catalogue.decision', $decision))->assertOk()->assertSee('Private note')->assertSee('Record correction');
        $this->actingAs(User::factory()->create())->get(route('admin.catalogue.decision', $decision))->assertForbidden();
    }

    public function test_queue_rechecks_central_capability_and_does_not_trust_cached_role(): void
    {
        $administrator = User::factory()->administrator()->create();
        $administrator->forceFill(['is_administrator' => false])->save();
        $this->expectException(AuthorizationException::class);
        app(CatalogueModerationQueue::class)->paginate($administrator, 'manual_submission');
    }

    public function test_public_catalogue_does_not_include_private_moderation_evidence(): void
    {
        config(['catalogue.read_cutover' => true]);
        $a = $this->item('Approved food');
        $a->forceFill(['status' => 'approved'])->save();
        $b = $this->item('Private pending');
        app(CatalogueCandidateRecorder::class)->record($a->id, $b->id, CatalogueDuplicateEvidence::ExactPrimaryName, explanation: 'PRIVATE MODERATION EXPLANATION');
        $this->get(route('catalogue.show', $a))->assertOk()->assertDontSee('PRIVATE MODERATION EXPLANATION')->assertDontSee('Catalogue moderation');
        $this->get(route('catalogue.show', $b))->assertNotFound();
    }

    public function test_queue_uses_bounded_eager_queries_without_row_by_row_loading(): void
    {
        $administrator = User::factory()->administrator()->create();
        foreach (range(1, 6) as $number) {
            $this->item('Food '.$number);
        }
        DB::enableQueryLog();
        $this->actingAs($administrator)->get(route('admin.catalogue.index'))->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $versionQueries = array_filter($queries, fn ($query) => str_contains($query['query'], 'from `catalogue_item_versions`'));
        $this->assertCount(1, $versionQueries);
    }

    private function item(string $name): CatalogueItem
    {
        $item = CatalogueItem::factory()->create();
        CatalogueItemVersion::factory()->current()->create(['catalogue_item_id' => $item->id, 'name' => $name]);

        return $item->fresh();
    }
}
