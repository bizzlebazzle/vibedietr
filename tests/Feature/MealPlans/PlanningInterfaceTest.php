<?php

namespace Tests\Feature\MealPlans;

use App\Models\MealPlan;
use App\Models\MealPlanDay;
use App\Models\MealPlanItemEntry;
use App\Models\MealPlanRecipeEntry;
use App\Models\MealPlanSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanningInterfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_time_input_consumption_correction_reversal_and_reconsumption_preserve_planning(): void
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $date = now('UTC')->subDay()->toDateString();
        $plan = MealPlan::factory()->for($owner, 'owner')->dated()->create(['starts_on' => $date, 'ends_on' => $date]);
        $day = MealPlanDay::factory()->for($plan)->create(['date' => $date, 'day_index' => null]);
        $slot = MealPlanSlot::factory()->for($day, 'day')->create();
        $entry = MealPlanRecipeEntry::factory()->for($slot, 'slot')->create(['planned_servings' => '2.00', 'recipe_snapshot' => ['title' => 'Soup']]);
        $route = route('meal-plans.consumption.store', [$plan, 'recipe', $entry]);

        $this->actingAs($owner)->get(route('meal-plans.show', $plan))->assertOk()
            ->assertSee('Soup: Planned — not consumed')->assertSee('Move Soup to day and slot')
            ->assertSee('Consumed local date and time')->assertSee('Consumption timezone (IANA name)');
        $this->post($route, ['consumed_local_at' => $date.'T12:30:45'])->assertSessionHasNoErrors();
        $this->get(route('meal-plans.show', $plan))->assertOk()
            ->assertSee('Soup: Consumed')->assertSee('Actual: 2 servings')
            ->assertSee('Save correction')->assertDontSee('Move Soup to day and slot');
        $this->patch($route, ['actual_amount' => '1.25', 'consumed_local_at' => $date.'T13:30:45'])
            ->assertSessionHasNoErrors()->assertSessionHas('planning_focus', 'entry-recipe-'.$entry->id);
        $this->assertSame('2.00', $entry->fresh()->planned_servings);
        $this->get(route('meal-plans.show', $plan))->assertSee('Actual: 1.25 servings')->assertSee($date.' 13:30:45');
        $this->delete($route)->assertSessionHasNoErrors();
        $this->get(route('meal-plans.show', $plan))->assertSee('Soup: Planned — consumption reversed')
            ->assertSee('Record consumption again')->assertDontSee('Move Soup to day and slot');
        $this->post($route, ['actual_amount' => '1.5', 'consumed_local_at' => $date.'T14:00'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('diary_consumption_transitions', 4);
        $this->assertSame('2.00', $entry->fresh()->planned_servings);
    }

    public function test_consumption_validation_keeps_input_only_on_the_submitted_entry(): void
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $date = now('UTC')->subDay()->toDateString();
        $plan = MealPlan::factory()->for($owner, 'owner')->dated()->create(['starts_on' => $date, 'ends_on' => $date]);
        $day = MealPlanDay::factory()->for($plan)->create(['date' => $date, 'day_index' => null]);
        $slot = MealPlanSlot::factory()->for($day, 'day')->create();
        $entry = MealPlanItemEntry::factory()->for($slot, 'slot')->create();
        $other = MealPlanItemEntry::factory()->for($slot, 'slot')->create();
        $this->actingAs($owner)->from(route('meal-plans.show', $plan))->post(route('meal-plans.consumption.store', [$plan, 'item', $entry]), [
            '_planning_form' => 'entry-item-'.$entry->id, 'actual_amount' => '0', 'timezone' => 'UTC',
        ])->assertSessionHasErrors('actual_amount');
        $response = $this->get(route('meal-plans.show', $plan))->assertOk()
            ->assertSee('data-validation-summary', false)->assertSee('data-feedback-error', false);
        $html = $response->getContent();
        $this->assertIsString($html);
        $this->assertMatchesRegularExpression('/id="entry-item-'.$entry->id.'-amount"[^>]*value="0"/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="entry-item-'.$other->id.'-amount"[^>]*value="0"/', $html);
        $this->assertDatabaseCount('diary_consumption_transitions', 0);
    }

    public function test_reusable_entries_explain_consumption_unavailability(): void
    {
        $owner = User::factory()->create();
        $plan = MealPlan::factory()->for($owner, 'owner')->reusable()->create();
        $day = MealPlanDay::factory()->for($plan)->create(['date' => null, 'day_index' => 0]);
        $slot = MealPlanSlot::factory()->for($day, 'day')->create();
        MealPlanItemEntry::factory()->for($slot, 'slot')->create();
        $this->actingAs($owner)->get(route('meal-plans.show', $plan))->assertOk()
            ->assertSee('Consumption is unavailable for reusable undated entries.')
            ->assertDontSee('Mark consumed');
    }
}
