<?php

namespace Tests\Feature\MealPlans;

use App\Audit\Enums\AuditAction;
use App\Domain\Diary\ConsumptionAction;
use App\Domain\Measurements\StandardUnit;
use App\Models\AuditEvent;
use App\Models\DiaryConsumptionState;
use App\Models\DiaryConsumptionTransition;
use App\Models\DiaryEntry;
use App\Models\MealPlan;
use App\Models\MealPlanDay;
use App\Models\MealPlanItemEntry;
use App\Models\MealPlanRecipeEntry;
use App\Models\MealPlanSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use LogicException;
use Tests\TestCase;

class MealPlanConsumptionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    public function test_first_consumption_defaults_to_planned_amount_and_current_local_time(): void
    {
        Date::setTestNow('2026-09-19 11:30:45 UTC');
        [$owner, $plan, $entry] = $this->datedRecipeEntry('2026-09-19', '1.50', 'Europe/London');

        $this->actingAs($owner)->post(route('meal-plans.consumption.store', [$plan, 'recipe', $entry]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $transition = DiaryConsumptionTransition::query()->sole();
        $this->assertSame(ConsumptionAction::Consume, $transition->action);
        $this->assertSame('1.500000000000000000', $transition->actual_amount);
        $this->assertSame('serving', $transition->actual_unit);
        $this->assertSame('2026-09-19 12:30:45', $transition->consumed_local_at->format('Y-m-d H:i:s'));
        $this->assertSame('Europe/London', $transition->timezone);
        $this->assertSame(60, $transition->utc_offset_minutes);
        $this->assertSame('2026-09-19 11:30:45', $transition->consumed_at_utc->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-19', $transition->effective_diary_date->toDateString());
        $this->assertSame('1.50', $entry->fresh()->planned_servings);
    }

    public function test_actual_amount_and_time_can_be_corrected_then_reversed_and_reconsumed_with_append_only_audit(): void
    {
        Date::setTestNow('2026-09-20 20:00:00 UTC');
        [$owner, $plan, $entry] = $this->datedRecipeEntry('2026-09-19', '2.00', 'Europe/London');
        $this->actingAs($owner)->post(route('meal-plans.consumption.store', [$plan, 'recipe', $entry]), [
            'actual_amount' => '1.25', 'consumed_local_at' => '2026-09-20 00:15',
        ])->assertSessionHasNoErrors();
        $first = DiaryConsumptionTransition::query()->sole();

        $this->patch(route('meal-plans.consumption.update', [$plan, 'recipe', $entry]), [
            'actual_amount' => '0.75', 'consumed_local_at' => '2026-09-19 19:45',
        ])->assertSessionHasNoErrors();
        $corrected = DiaryConsumptionTransition::query()->where('sequence', 2)->sole();
        $this->assertSame(ConsumptionAction::Correct, $corrected->action);
        $this->assertSame($first->id, $corrected->predecessor_id);
        $this->assertSame('0.750000000000000000', $corrected->actual_amount);
        $this->assertSame('2.00', $entry->fresh()->planned_servings);

        $this->delete(route('meal-plans.consumption.destroy', [$plan, 'recipe', $entry]))->assertSessionHasNoErrors();
        $reversal = DiaryConsumptionTransition::query()->where('sequence', 3)->sole();
        $this->assertSame(ConsumptionAction::Reverse, $reversal->action);
        $this->assertSame($corrected->id, $reversal->target_transition_id);
        $this->assertNull($reversal->actual_amount);
        $this->assertNull(DiaryConsumptionState::query()->where('meal_plan_recipe_entry_id', $entry->id)->sole()->current_transition_id);

        $this->post(route('meal-plans.consumption.store', [$plan, 'recipe', $entry]), [
            'consumed_local_at' => '2026-09-19 20:00',
        ])->assertSessionHasErrors('actual_amount');
        $this->post(route('meal-plans.consumption.store', [$plan, 'recipe', $entry]), [
            'actual_amount' => '1.10', 'consumed_local_at' => '2026-09-19 20:00',
        ])->assertSessionHasNoErrors();
        $reconsumed = DiaryConsumptionTransition::query()->where('sequence', 4)->sole();
        $this->assertSame(ConsumptionAction::Reconsume, $reconsumed->action);
        $this->assertSame($reversal->id, $reconsumed->predecessor_id);

        $events = AuditEvent::query()->where('action', AuditAction::DiaryConsumptionTransitioned->value)->orderBy('occurred_at')->get();
        $this->assertCount(4, $events);
        $this->assertEqualsCanonicalizing(['consume', 'correct', 'reverse', 'reconsume'], $events->pluck('payload.transition_kind')->all());
        foreach ($events as $event) {
            $this->assertSame('completed', $event->payload['outcome']);
            $this->assertCount(2, $event->payload);
        }
        $this->expectException(LogicException::class);
        $first->forceFill(['actual_amount' => '9'])->save();
    }

    public function test_item_consumption_keeps_planned_amount_and_unit_separate(): void
    {
        Date::setTestNow('2026-09-19 18:00:00 UTC');
        [$owner, $plan, , $slot] = $this->datedPlan('2026-09-19');
        $entry = MealPlanItemEntry::factory()->for($slot, 'slot')->create(['planned_amount' => '250', 'planned_unit' => StandardUnit::Gram]);
        $this->actingAs($owner)->post(route('meal-plans.consumption.store', [$plan, 'item', $entry]), [
            'actual_amount' => '175.25',
        ])->assertSessionHasNoErrors();
        $transition = DiaryConsumptionTransition::query()->sole();
        $this->assertSame('175.250000000000000000', $transition->actual_amount);
        $this->assertSame(StandardUnit::Gram->value, $transition->actual_unit);
        $this->assertSame('250.000000000000000000', $entry->fresh()->planned_amount);
    }

    public function test_reusable_entry_cross_owner_access_and_invalid_dates_are_rejected(): void
    {
        Date::setTestNow('2026-09-20 20:00:00 UTC');
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $plan = MealPlan::factory()->for($owner, 'owner')->reusable()->create();
        $day = MealPlanDay::factory()->for($plan)->create(['day_index' => 0, 'date' => null]);
        $slot = MealPlanSlot::factory()->for($day, 'day')->create();
        $entry = MealPlanRecipeEntry::factory()->for($slot, 'slot')->create();
        $this->actingAs($owner)->post(route('meal-plans.consumption.store', [$plan, 'recipe', $entry]), [
            'consumed_local_at' => '2026-09-20 12:00',
        ])->assertSessionHasErrors('consumption');
        $this->assertDatabaseCount('diary_consumption_transitions', 0);

        [$owner, $dated, $datedEntry] = $this->datedRecipeEntry('2026-09-19', '1.00', 'UTC');
        $other = User::factory()->create();
        $this->actingAs($other)->post(route('meal-plans.consumption.store', [$dated, 'recipe', $datedEntry]), [
            'consumed_local_at' => '2026-09-19 12:00',
        ])->assertNotFound();
        $this->actingAs($owner)->post(route('meal-plans.consumption.store', [$dated, 'recipe', $datedEntry]), [
            'consumed_local_at' => '2026-09-21 01:00',
        ])->assertSessionHasErrors('consumed_local_at');

        [$futureOwner, $futurePlan, $futureEntry] = $this->datedRecipeEntry('2026-09-20', '1.00', 'UTC');
        $this->actingAs($futureOwner)->post(route('meal-plans.consumption.store', [$futurePlan, 'recipe', $futureEntry]), [
            'consumed_local_at' => '2026-09-20 21:00',
        ])->assertSessionHasErrors('consumed_local_at');
    }

    public function test_dst_gaps_ambiguity_and_future_times_require_explicit_valid_resolution(): void
    {
        Date::setTestNow('2026-10-25 12:00:00 UTC');
        [$owner, $plan, $entry] = $this->datedRecipeEntry('2026-10-25', '1.00', 'Europe/London');
        $this->actingAs($owner)->post(route('meal-plans.consumption.store', [$plan, 'recipe', $entry]), [
            'consumed_local_at' => '2026-10-25 01:30',
        ])->assertSessionHasErrors('utc_offset_minutes');
        $this->post(route('meal-plans.consumption.store', [$plan, 'recipe', $entry]), [
            'consumed_local_at' => '2026-10-25 01:30', 'utc_offset_minutes' => 60,
        ])->assertSessionHasNoErrors();
        $this->assertSame(60, DiaryConsumptionTransition::query()->sole()->utc_offset_minutes);

        Date::setTestNow('2026-03-30 12:00:00 UTC');
        [$owner2, $plan2, $entry2] = $this->datedRecipeEntry('2026-03-29', '1.00', 'Europe/London');
        $this->actingAs($owner2)->post(route('meal-plans.consumption.store', [$plan2, 'recipe', $entry2]), [
            'consumed_local_at' => '2026-03-29 01:30',
        ])->assertSessionHasErrors('consumed_local_at');
    }

    public function test_ad_hoc_diary_entry_is_owner_only_and_requires_explicit_actual_quantity(): void
    {
        Date::setTestNow('2026-09-19 23:30:00 UTC');
        $owner = User::factory()->create(['timezone' => 'Europe/London']);
        $this->actingAs($owner)->post(route('diary-entries.store'), [
            'kind' => 'one_off', 'one_off_wording' => 'Late snack',
            'actual_amount' => '1.5', 'actual_unit' => StandardUnit::Portion->value,
            'effective_diary_date' => '2026-09-19',
        ])->assertSessionHasNoErrors();
        $entry = DiaryEntry::query()->sole();
        $transition = DiaryConsumptionTransition::query()->sole();
        $this->assertSame('Late snack', $entry->source_snapshot['wording']);
        $this->assertSame('1.500000000000000000', $transition->actual_amount);
        $this->assertSame('2026-09-19', $transition->effective_diary_date->toDateString());

        $other = User::factory()->create();
        $this->actingAs($other)->delete(route('diary-entries.consumption.destroy', $entry))->assertNotFound();
        $this->assertNotNull(DiaryConsumptionState::query()->where('diary_entry_id', $entry->id)->sole()->current_transition_id);

        $this->actingAs($owner)->post(route('diary-entries.store'), [
            'kind' => 'one_off', 'one_off_wording' => 'Missing amount', 'actual_unit' => StandardUnit::Portion->value,
        ])->assertSessionHasErrors('actual_amount');
    }

    /** @return array{User, MealPlan, MealPlanRecipeEntry} */
    private function datedRecipeEntry(string $date, string $planned, string $timezone): array
    {
        [$owner, $plan, , $slot] = $this->datedPlan($date, $timezone);

        return [$owner, $plan, MealPlanRecipeEntry::factory()->for($slot, 'slot')->create(['planned_servings' => $planned])];
    }

    /** @return array{User, MealPlan, MealPlanDay, MealPlanSlot} */
    private function datedPlan(string $date, string $timezone = 'UTC'): array
    {
        $owner = User::factory()->create(['timezone' => $timezone]);
        $plan = MealPlan::factory()->for($owner, 'owner')->dated()->create(['starts_on' => $date, 'ends_on' => $date]);
        $day = MealPlanDay::factory()->for($plan)->create(['day_index' => null, 'date' => $date]);
        $slot = MealPlanSlot::factory()->for($day, 'day')->create();

        return [$owner, $plan, $day, $slot];
    }
}
