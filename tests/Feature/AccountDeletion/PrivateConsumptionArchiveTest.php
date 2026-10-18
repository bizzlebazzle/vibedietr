<?php

namespace Tests\Feature\AccountDeletion;

use App\Domain\AccountDeletion\AccountLifecycle;
use App\Domain\AccountDeletion\PurgeAccount;
use App\Domain\AccountExports\AccountExportProjection;
use App\Domain\MealPlans\MealPlanBookmarkManager;
use App\Models\DiaryConsumptionTransition;
use App\Models\MealPlan;
use App\Models\MealPlanBookmark;
use App\Models\MealPlanDay;
use App\Models\MealPlanRecipeEntry;
use App\Models\MealPlanSlot;
use App\Models\PrivateConsumptionArchive;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesErasureJournal;
use Tests\TestCase;

class PrivateConsumptionArchiveTest extends TestCase
{
    use RefreshDatabase, UsesErasureJournal;

    public function test_last_bookmark_archives_history_and_recovery_cannot_restore_the_removed_plan(): void
    {
        $this->freezeSecond();
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $plan = MealPlan::factory()->for($owner, 'owner')->dated()->create([
            'starts_on' => today(), 'ends_on' => today(),
        ]);
        $day = MealPlanDay::factory()->for($plan)->create(['date' => today(), 'day_index' => null]);
        $slot = MealPlanSlot::factory()->for($day, 'day')->create();
        $entry = MealPlanRecipeEntry::factory()->for($slot, 'slot')->create([
            'recipe_snapshot' => ['title' => 'Public meal', 'visibility' => 'public', 'servings' => '2.00', 'ingredients' => [], 'sections' => [], 'steps' => []],
        ]);
        $this->actingAs($owner)->post(route('meal-plans.consumption.store', [$plan, 'recipe', $entry]), [
            'actual_amount' => '0.75',
        ])->assertSessionHasNoErrors();
        $original = DiaryConsumptionTransition::query()->sole()->getRawOriginal();
        $plan->forceFill(['visibility' => 'public', 'published_at' => now()])->save();
        $bookmark = MealPlanBookmark::factory()->for($plan)->create();
        $reader = User::query()->findOrFail($bookmark->user_id);
        app(AccountLifecycle::class)->request($owner);
        app(MealPlanBookmarkManager::class)->remove($bookmark, $reader);
        $this->assertDatabaseMissing('meal_plans', ['id' => $plan->id]);
        $archive = PrivateConsumptionArchive::query()->sole();
        $this->assertSame($owner->id, $archive->user_id);
        $this->assertSame($original, $archive->history['transitions'][0]);
        $this->assertStringNotContainsString($original['id'], DB::table('private_consumption_archives')->value('history'));
        $this->assertDatabaseCount('diary_consumption_transitions', 0);
        app(AccountLifecycle::class)->recover($owner);
        $this->assertTrue($owner->isActive());
        $domains = app(AccountExportProjection::class)->domains($owner->id);
        $history = $domains['diary']()->archived_history;
        $this->assertCount(1, $history);
        $this->assertSame('0.750000000000000000', $history[0]->transitions[0]->actual_amount);
        $this->assertStringNotContainsString('actor_user_id', json_encode($history));
        $other = app(AccountExportProjection::class)->domains($reader->id);
        $this->assertSame([], $other['diary']()->archived_history);
        $this->assertDatabaseMissing('meal_plans', ['id' => $plan->id]);
        $this->assertSame($original, $archive->fresh()->history['transitions'][0]);
        $this->actingAs($reader)->get(route('meal-plans.show', $plan))->assertNotFound();
        $purge = app(AccountLifecycle::class)->request($owner, waive: true);
        app(PurgeAccount::class)->purge($purge->id);
        $this->assertDatabaseCount('private_consumption_archives', 0);
        $this->assertDatabaseMissing('audit_events', ['id' => $original['audit_event_id']]);
    }
}
