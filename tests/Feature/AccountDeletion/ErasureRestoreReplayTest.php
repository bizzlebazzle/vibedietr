<?php

namespace Tests\Feature\AccountDeletion;

use App\Domain\AccountDeletion\AccountLifecycle;
use App\Domain\AccountDeletion\ErasureJournalState;
use App\Domain\AccountDeletion\ErasureRestoreReplay;
use App\Domain\AccountDeletion\JournalManifest;
use App\Domain\AccountDeletion\JournalUnavailable;
use App\Domain\AccountDeletion\ProtectedErasureJournal;
use App\Domain\AccountDeletion\PurgeAccount;
use App\Jobs\ProcessAccountDeletion;
use App\Models\MealPlan;
use App\Models\MealPlanBookmark;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\UsesErasureJournal;
use Tests\TestCase;

class ErasureRestoreReplayTest extends TestCase
{
    use RefreshDatabase, UsesErasureJournal;

    protected function setUp(): void
    {
        parent::setUp();
        config(['erasure.privacy_operator' => 'synthetic-restore-operator', 'erasure.operator_environment' => 'testing']);
    }

    public function test_pre_request_sql_replay_reinstates_original_recovery_clock_and_retained_access(): void
    {
        $this->freezeSecond();
        $owner = User::factory()->create();
        $plan = MealPlan::factory()->public()->for($owner, 'owner')->create();
        MealPlanBookmark::factory()->for($plan)->create();
        $request = app(AccountLifecycle::class)->request($owner);
        $deadline = $request->deadline;
        DB::table('account_deletions')->delete();
        DB::table('meal_plans')->where('id', $plan->id)->update(['user_id' => $owner->id, 'visibility' => 'public', 'retained_unlisted_at' => null]);
        $this->travel(2)->days();
        $this->replay();
        $this->assertFalse($owner->isActive());
        $this->assertSame($deadline->timestamp, $owner->accountDeletion()->sole()->deadline->timestamp);
        $this->assertNull($plan->fresh()->user_id);
        $this->assertSame('retained_unlisted', $plan->fresh()->visibility->value);
        $this->replay();
        app(AccountLifecycle::class)->recover($owner);
        $this->assertTrue($owner->isActive());
        $this->assertSame($owner->id, $plan->fresh()->user_id);
    }

    public function test_completed_purge_reapplies_to_restored_rows_without_reclocking_receipts_or_deleting_copies(): void
    {
        $owner = User::factory()->create();
        $recipe = Recipe::factory()->validDraft()->for($owner, 'owner')->create();
        $copy = Recipe::factory()->validDraft()->create();
        $userRow = (array) DB::table('users')->find($owner->id);
        $recipeRow = (array) DB::table('recipes')->find($recipe->id);
        $request = app(AccountLifecycle::class)->request($owner, waive: true);
        app(PurgeAccount::class)->purge($request->id);
        $receipts = DB::table('account_erasure_receipts')->get()->toArray();
        DB::table('users')->insert($userRow);
        DB::table('recipes')->insert($recipeRow);
        $this->replay();
        $this->assertDatabaseMissing('users', ['id' => $owner->id]);
        $this->assertDatabaseMissing('recipes', ['id' => $recipe->id]);
        $this->assertDatabaseHas('recipes', ['id' => $copy->id]);
        $this->assertEquals($receipts, DB::table('account_erasure_receipts')->get()->toArray());
        $this->replay();
        $this->assertEquals($receipts, DB::table('account_erasure_receipts')->get()->toArray());
    }

    public function test_due_incomplete_request_uses_original_deadline(): void
    {
        $owner = User::factory()->create();
        $request = app(AccountLifecycle::class)->request($owner);
        $this->travelTo($request->deadline);
        $this->replay();
        $this->assertDatabaseMissing('users', ['id' => $owner->id]);
        $this->assertDatabaseCount('account_erasure_receipts', 0);
    }

    public function test_cancelled_request_in_a_pre_request_restore_does_not_purge_the_recovered_owner(): void
    {
        $owner = User::factory()->create();
        $request = app(AccountLifecycle::class)->request($owner);
        app(AccountLifecycle::class)->recover($owner);
        $this->travelTo($request->deadline->addDay());
        $this->replay();
        $this->assertTrue($owner->isActive());
        $this->assertDatabaseCount('account_deletions', 0);
    }

    public function test_older_changed_public_body_is_not_published_during_recoverable_replay(): void
    {
        $owner = User::factory()->create();
        $plan = MealPlan::factory()->public()->for($owner, 'owner')->create();
        MealPlanBookmark::factory()->for($plan)->create();
        app(AccountLifecycle::class)->request($owner);
        DB::table('account_deletions')->delete();
        DB::table('meal_plans')->where('id', $plan->id)->update(['user_id' => $owner->id, 'visibility' => 'public', 'retained_unlisted_at' => null, 'name' => 'Earlier private draft']);
        $this->expectException(JournalUnavailable::class);
        $this->replay();
    }

    public function test_pre_generation_rows_fail_closed(): void
    {
        $owner = User::factory()->create();
        DB::table('users')->where('id', $owner->id)->update(['account_generation' => null]);
        $this->expectException(JournalUnavailable::class);
        $this->replay();
    }

    public function test_sql_commit_before_completion_acknowledgement_is_retried_from_the_independent_journal(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $request = app(AccountLifecycle::class)->request($owner, waive: true);
        $intent = app(AccountLifecycle::class)->intent($request, 'purge_started');
        app(JournalManifest::class)->append((string) Str::ulid(), $intent);
        app(PurgeAccount::class)->erase($intent, $intent['resources']);
        $this->assertDatabaseCount('account_deletions', 0);
        $this->artisan('accounts:process-deletions')->assertSuccessful();
        Queue::assertPushed(ProcessAccountDeletion::class,
            fn ($job) => $job->deletionId === $request->id);
        app(PurgeAccount::class)->purge($request->id);
        $journal = app(ProtectedErasureJournal::class);
        $entries = app(ErasureJournalState::class)->at($journal->checkpoint());
        $this->assertSame('purged', $entries[array_key_last($entries)]['state']);
        $this->assertDatabaseCount('account_erasure_receipts', 1);
        app(PurgeAccount::class)->purge($request->id);
        $this->assertDatabaseCount('account_erasure_receipts', 1);
    }

    private function replay(): void
    {
        app(ErasureRestoreReplay::class)->replay(app(ProtectedErasureJournal::class)->checkpoint());
    }
}
