<?php

namespace Tests\Feature\AccountDeletion;

use App\Domain\AccountDeletion\AccountLifecycle;
use App\Domain\AccountDeletion\PublicContentMinimizer;
use App\Domain\AccountDeletion\PurgeAccount;
use App\Domain\MealPlans\MealPlanCopier;
use App\Models\MealPlan;
use App\Models\MealPlanBookmark;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesErasureJournal;
use Tests\TestCase;

class PurgeAccountTest extends TestCase
{
    use RefreshDatabase, UsesErasureJournal;

    public function test_due_purge_removes_private_resources_and_anonymizes_public_content_idempotently(): void
    {
        $owner = User::factory()->create();
        $private = Recipe::factory()->finalizedPrivate()->for($owner, 'owner')->create();
        $public = Recipe::factory()->finalizedPublic()->for($owner, 'owner')->create();
        $draft = Recipe::factory()->validDraft()->for($owner, 'owner')->create();
        $plan = MealPlan::factory()->public()->for($owner, 'owner')->create();
        $unavailable = MealPlan::factory()->public()->for($owner, 'owner')->create();
        $bookmark = MealPlanBookmark::factory()->for($plan)->create();
        $copy = app(MealPlanCopier::class)->copy($plan->id, User::query()->findOrFail($bookmark->user_id));
        $request = app(AccountLifecycle::class)->request($owner);
        app(PurgeAccount::class)->purge($request->id);
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        app(AccountLifecycle::class)->waive($owner);
        app(PurgeAccount::class)->purge($request->id);
        app(PurgeAccount::class)->purge($request->id);
        $this->assertDatabaseMissing('users', ['id' => $owner->id]);
        $this->assertDatabaseMissing('recipes', ['id' => $private->id]);
        $this->assertDatabaseMissing('recipes', ['id' => $draft->id]);
        $this->assertDatabaseMissing('meal_plans', ['id' => $unavailable->id]);
        $this->assertNull($public->fresh()->user_id);
        $this->assertSame('Former VibeDietr user', $public->fresh()->currentVersion->public_attribution_name);
        $this->assertDatabaseHas('meal_plans', ['id' => $plan->id, 'user_id' => null, 'visibility' => 'retained_unlisted']);
        $this->assertSame($bookmark->user_id, $copy->fresh()->user_id);
        $this->assertDatabaseCount('account_erasure_receipts', 1);
    }

    public function test_public_projection_rejects_unknown_data_and_strips_recovery_attribution(): void
    {
        $minimizer = app(PublicContentMinimizer::class);
        $snapshot = ['title' => 'Public recipe', 'visibility' => 'public', 'public_attribution_name' => 'secret-source',
            'ingredients' => [], 'sections' => [], 'steps' => []];
        $this->assertArrayNotHasKey('public_attribution_name', $minimizer->recipe($snapshot));
        $this->expectException(\RuntimeException::class);
        $minimizer->recipe($snapshot + ['unclassified_private_field' => 'synthetic-secret']);
    }

    public function test_under13_purge_removes_public_sources_but_preserves_independent_copies(): void
    {
        $owner = User::factory()->create();
        $public = Recipe::factory()->finalizedPublic()->for($owner, 'owner')->create();
        $plan = MealPlan::factory()->public()->for($owner, 'owner')->create();
        $bookmark = MealPlanBookmark::factory()->for($plan)->create();
        $copy = app(MealPlanCopier::class)->copy($plan->id, User::query()->findOrFail($bookmark->user_id));
        $request = app(AccountLifecycle::class)->request($owner, under13: true);
        app(PurgeAccount::class)->purge($request->id);
        $this->assertDatabaseMissing('users', ['id' => $owner->id]);
        $this->assertDatabaseMissing('recipes', ['id' => $public->id]);
        $this->assertDatabaseMissing('meal_plans', ['id' => $plan->id]);
        $this->assertDatabaseHas('meal_plans', ['id' => $copy->id, 'user_id' => $bookmark->user_id]);
    }
}
