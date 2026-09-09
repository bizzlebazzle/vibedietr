<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Catalogue\CatalogueCandidateRecorder;
use App\Domain\Catalogue\CatalogueDuplicateEvidence;
use App\Models\CatalogueItem;
use App\Models\CatalogueModerationDecision;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CatalogueModerationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pair_order_and_retries_reuse_one_candidate_and_event(): void
    {
        $a = CatalogueItem::factory()->create();
        $b = CatalogueItem::factory()->create();
        $recorder = app(CatalogueCandidateRecorder::class);
        $first = $recorder->record($b->id, $a->id, CatalogueDuplicateEvidence::ExactPrimaryName);
        $second = $recorder->record($a->id, $b->id, CatalogueDuplicateEvidence::ExactPrimaryName);
        $this->assertSame($first->id, $second->id);
        $this->assertLessThan($first->second_catalogue_item_id, $first->first_catalogue_item_id);
        $this->assertDatabaseCount('catalogue_duplicate_candidates', 1);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_self_pair_is_rejected(): void
    {
        $item = CatalogueItem::factory()->create();
        $this->expectException(ValidationException::class);
        app(CatalogueCandidateRecorder::class)->record($item->id, $item->id, CatalogueDuplicateEvidence::ExactPrimaryName);
    }

    public function test_database_rejects_duplicate_pair_without_model_guard(): void
    {
        $a = CatalogueItem::factory()->create();
        $b = CatalogueItem::factory()->create();
        $candidate = app(CatalogueCandidateRecorder::class)->record($a->id, $b->id, CatalogueDuplicateEvidence::ExactPrimaryName);
        $this->expectException(QueryException::class);
        DB::table('catalogue_duplicate_candidates')->insert($candidate->only(['first_catalogue_item_id', 'second_catalogue_item_id', 'status', 'evidence']));
    }

    public function test_decision_history_prevents_identity_deletion(): void
    {
        $item = CatalogueItem::factory()->create();
        CatalogueModerationDecision::query()->forceCreate([
            'catalogue_item_id' => $item->id, 'action' => 'approve', 'reason_code' => 'reviewed', 'evidence' => [],
        ]);
        $this->expectException(QueryException::class);
        DB::table('catalogue_items')->where('id', $item->id)->delete();
    }

    public function test_decision_model_rejects_history_rewriting(): void
    {
        $decision = CatalogueModerationDecision::query()->forceCreate([
            'catalogue_item_id' => CatalogueItem::factory()->create()->id,
            'action' => 'approve', 'reason_code' => 'reviewed', 'evidence' => [],
        ]);
        $this->expectException(\LogicException::class);
        $decision->forceFill(['action' => 'reject'])->save();
    }
}
