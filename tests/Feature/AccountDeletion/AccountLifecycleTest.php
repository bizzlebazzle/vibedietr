<?php

namespace Tests\Feature\AccountDeletion;

use App\Domain\AccountDeletion\AccountLifecycle;
use App\Domain\AccountDeletion\JournalUnavailable;
use App\Models\AccountDeletion;
use App\Models\MealPlan;
use App\Models\MealPlanBookmark;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\UsesErasureJournal;
use Tests\TestCase;

class AccountLifecycleTest extends TestCase
{
    use RefreshDatabase, UsesErasureJournal;

    public function test_request_is_inactive_idempotent_and_recovers_all_original_plans(): void
    {
        $this->freezeSecond();
        $user = User::factory()->create();
        $public = MealPlan::factory()->public()->for($user, 'owner')->create();
        $unavailable = MealPlan::factory()->public()->for($user, 'owner')->create();
        MealPlanBookmark::factory()->for($public, 'mealPlan')->create();
        $service = app(AccountLifecycle::class);
        $request = $service->request($user);
        $this->assertFalse($user->isActive());
        $this->assertSame('recoverable', $request->state);
        $this->assertSame(now()->addDays(30)->timestamp, $request->deadline->timestamp);
        $this->assertNull($public->fresh()->user_id);
        $this->assertSame('retained_unlisted', $public->fresh()->visibility->value);
        $this->assertSame($user->id, $unavailable->fresh()->user_id);
        $this->travel(1)->days();
        $this->assertSame($request->id, $service->request($user)->id);
        $this->assertSame($request->deadline->timestamp, $request->fresh()->deadline->timestamp);
        $service->recover($user);
        $this->assertTrue($user->isActive());
        $this->assertSame($user->id, $public->fresh()->user_id);
        $this->assertSame('public', $public->fresh()->visibility->value);
        $this->assertSame('public', $unavailable->fresh()->visibility->value);
    }

    public function test_inactive_account_cannot_use_existing_session_and_unavailable_public_plan_is_denied(): void
    {
        $user = User::factory()->create();
        $plan = MealPlan::factory()->public()->for($user, 'owner')->create();
        app(AccountLifecycle::class)->request($user);
        $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
        $this->assertGuest();
        $this->get(route('meal-plans.show', $plan))->assertNotFound();
    }

    public function test_recovery_is_denied_at_the_exact_original_deadline(): void
    {
        $user = User::factory()->create();
        $request = app(AccountLifecycle::class)->request($user);
        $this->travelTo($request->deadline);
        $this->expectException(ValidationException::class);
        app(AccountLifecycle::class)->recover($user);
    }

    public function test_waiver_shortens_the_deadline_and_cannot_be_recovered(): void
    {
        $this->freezeSecond();
        $user = User::factory()->create();
        $request = app(AccountLifecycle::class)->request($user);
        app(AccountLifecycle::class)->waive($user);
        $this->assertSame('waived', $request->fresh()->reason);
        $this->assertSame(now()->timestamp, $request->fresh()->deadline->timestamp);
        $this->expectException(ValidationException::class);
        app(AccountLifecycle::class)->recover($user);
    }

    public function test_under13_never_retains_a_public_source_plan(): void
    {
        $user = User::factory()->create();
        $public = MealPlan::factory()->public()->for($user, 'owner')->create();
        MealPlanBookmark::factory()->for($public, 'mealPlan')->create();
        $request = app(AccountLifecycle::class)->request($user, under13: true);
        $this->assertSame('under13', $request->reason);
        $this->assertTrue($request->deadline->isPast());
        $this->assertSame($user->id, $public->fresh()->user_id);
        $this->expectException(ValidationException::class);
        app(AccountLifecycle::class)->recover($user);
    }

    public function test_replacement_first_rule_counts_only_active_administrators(): void
    {
        $first = User::factory()->administrator()->create();
        try {
            app(AccountLifecycle::class)->request($first);
            $this->fail('Sole administrator deletion accepted.');
        } catch (\RuntimeException) {
            $this->assertTrue($first->isActive());
            $this->assertDatabaseCount('account_deletions', 0);
        }
        $replacement = User::factory()->administrator()->create();
        app(AccountLifecycle::class)->request($first);
        $this->assertFalse($first->isAdministrator());
        $this->expectException(\RuntimeException::class);
        app(AccountLifecycle::class)->request($replacement);
    }

    public function test_journal_failure_preserves_original_prepared_deadline_and_denies_acknowledgement(): void
    {
        $user = User::factory()->create();
        DB::connection('lifecycle_journal_mirror')->getSchemaBuilder()->drop('erasure_journal_entries');
        try {
            app(AccountLifecycle::class)->request($user);
            $this->fail('Unavailable journal accepted.');
        } catch (JournalUnavailable) {
            $row = AccountDeletion::query()->sole();
            $this->assertSame('preparing', $row->state);
            $this->assertFalse($user->isActive());
            $this->assertSame($user->account_generation, $row->account_generation);
        }
    }
}
