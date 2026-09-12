<?php

namespace Tests\Feature\MealPlans;

use App\Domain\MealPlans\MealPlanType;
use App\Domain\MealPlans\PlanSlotKey;
use App\Models\MealPlan;
use App\Models\MealPlanDay;
use App\Models\MealPlanSlot;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MealPlanDaySlotTest extends TestCase
{
    use RefreshDatabase;

    public function test_reusable_and_dated_days_receive_the_same_ordered_default_slots(): void
    {
        $owner = User::factory()->create();
        $reusable = MealPlan::factory()->for($owner, 'owner')->reusable()->create();
        $dated = MealPlan::factory()->for($owner, 'owner')->dated()->create([
            'starts_on' => '2026-10-05',
            'ends_on' => '2026-10-11',
        ]);
        $this->actingAs($owner);

        $this->post(route('meal-plans.days.store', $reusable), ['day_index' => 0])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('meal-plans.days.store', $dated), ['date' => '2026-10-07'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $expectedKeys = array_map(static fn (PlanSlotKey $key): string => $key->value, PlanSlotKey::cases());
        $expectedNames = array_map(static fn (PlanSlotKey $key): string => $key->defaultName(), PlanSlotKey::cases());

        foreach ([$reusable, $dated] as $plan) {
            $day = $plan->days()->sole();
            $this->assertSame($expectedKeys, $day->slots->pluck('standard_key')->map->value->all());
            $this->assertSame($expectedNames, $day->slots->pluck('name')->all());
            $this->assertSame([0, 1, 2, 3, 4], $day->slots->pluck('position')->all());
        }

        $this->get(route('meal-plans.show', $reusable))
            ->assertOk()
            ->assertSeeInOrder($expectedNames)
            ->assertSee('Add slot')
            ->assertSee('Save slot order');
    }

    public function test_standard_meal_slots_and_custom_slots_can_be_renamed(): void
    {
        [$owner, $plan, $day] = $this->reusableDay();
        $this->actingAs($owner);

        foreach ([
            PlanSlotKey::Breakfast->value => 'Morning meal',
            PlanSlotKey::Lunch->value => 'Midday meal',
            PlanSlotKey::Dinner->value => 'Evening meal',
        ] as $key => $name) {
            $slot = $day->slots()->where('standard_key', $key)->sole();
            $this->patch(route('meal-plans.days.slots.update', [$plan, $day, $slot]), ['name' => $name])
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame($name, $slot->fresh()->name);
        }

        $this->post(route('meal-plans.days.slots.store', [$plan, $day]), ['name' => 'Supper'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $custom = $day->slots()->whereNull('standard_key')->sole();
        $this->patch(route('meal-plans.days.slots.update', [$plan, $day, $custom]), ['name' => 'Late supper'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Late supper', $custom->fresh()->name);
    }

    public function test_drinks_and_snacks_reject_rename_attempts(): void
    {
        [$owner, $plan, $day] = $this->reusableDay();
        $this->actingAs($owner);

        foreach ([PlanSlotKey::Drinks, PlanSlotKey::Snacks] as $key) {
            $slot = $day->slots()->where('standard_key', $key->value)->sole();
            $this->patch(route('meal-plans.days.slots.update', [$plan, $day, $slot]), ['name' => 'Changed'])
                ->assertRedirect()->assertSessionHasErrors('name');
            $this->assertSame($key->defaultName(), $slot->fresh()->name);
        }
    }

    public function test_database_also_rejects_a_fixed_slot_name_change(): void
    {
        [, , $day] = $this->reusableDay();
        $drinks = $day->slots()->where('standard_key', PlanSlotKey::Drinks->value)->sole();

        $this->expectException(QueryException::class);
        $drinks->name = 'Hydration';
        $drinks->save();
    }

    public function test_owner_can_add_extra_slots_and_control_the_complete_order(): void
    {
        [$owner, $plan, $day] = $this->reusableDay();
        $this->actingAs($owner);

        $this->post(route('meal-plans.days.slots.store', [$plan, $day]), ['name' => 'Afternoon tea'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $slots = $day->fresh()->slots;
        $custom = $slots->last();
        $orderedIds = [$custom->id, ...$slots->take(5)->pluck('id')->all()];

        $this->put(route('meal-plans.days.slots.reorder', [$plan, $day]), ['slot_ids' => $orderedIds])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull($custom->standard_key);
        $this->assertSame($orderedIds, $day->fresh()->slots->pluck('id')->all());
        $this->assertSame(range(0, 5), $day->fresh()->slots->pluck('position')->all());
    }

    public function test_reorder_rejects_incomplete_duplicate_and_cross_day_slot_ids(): void
    {
        [$owner, $plan, $day] = $this->reusableDay();
        $otherDay = $this->post(route('meal-plans.days.store', $plan), ['day_index' => 1]);
        $otherDay->assertRedirect()->assertSessionHasNoErrors();
        $otherDayModel = $plan->days()->where('day_index', 1)->sole();
        $ids = $day->slots->pluck('id')->all();
        $foreignId = $otherDayModel->slots->firstOrFail()->id;
        $this->actingAs($owner);

        foreach ([array_slice($ids, 1), [$ids[0], $ids[0], ...array_slice($ids, 2)], [$foreignId, ...array_slice($ids, 1)]] as $order) {
            $this->put(route('meal-plans.days.slots.reorder', [$plan, $day]), ['slot_ids' => $order])
                ->assertRedirect()->assertSessionHasErrors('slot_ids');
            $this->assertSame($ids, $day->fresh()->slots->pluck('id')->all());
        }
    }

    public function test_day_identity_follows_reusable_index_and_dated_calendar_semantics(): void
    {
        $owner = User::factory()->create();
        $reusable = MealPlan::factory()->for($owner, 'owner')->reusable()->create();
        $dated = MealPlan::factory()->for($owner, 'owner')->dated()->create([
            'starts_on' => '2026-10-05',
            'ends_on' => '2026-10-11',
        ]);
        $this->actingAs($owner);

        $this->post(route('meal-plans.days.store', $reusable), ['day_index' => 0])->assertSessionHasNoErrors();
        $this->post(route('meal-plans.days.store', $reusable), ['date' => '2026-10-05'])->assertSessionHasErrors('day_index');
        $this->post(route('meal-plans.days.store', $reusable), ['day_index' => 0])->assertSessionHasErrors('day_index');
        $this->post(route('meal-plans.days.store', $dated), ['date' => '2026-10-05'])->assertSessionHasNoErrors();
        $this->post(route('meal-plans.days.store', $dated), ['day_index' => 0])->assertSessionHasErrors('date');
        $this->post(route('meal-plans.days.store', $dated), ['date' => '2026-10-12'])->assertSessionHasErrors('date');
        $this->post(route('meal-plans.days.store', $dated), ['date' => '2026-10-05'])->assertSessionHasErrors('date');

        $this->assertSame(0, $reusable->days()->sole()->day_index);
        $this->assertNull($reusable->days()->sole()->date);
        $this->assertNull($dated->days()->sole()->day_index);
        $this->assertSame('2026-10-05', $dated->days()->sole()->date?->toDateString());
    }

    public function test_non_owner_cannot_create_or_mutate_days_and_slots(): void
    {
        [$owner, $plan, $day] = $this->reusableDay();
        $slot = $day->slots->firstOrFail();
        $other = User::factory()->create();
        $this->actingAs($other);

        $this->post(route('meal-plans.days.store', $plan), ['day_index' => 1])->assertNotFound();
        $this->post(route('meal-plans.days.slots.store', [$plan, $day]), ['name' => 'Foreign'])->assertNotFound();
        $this->patch(route('meal-plans.days.slots.update', [$plan, $day, $slot]), ['name' => 'Foreign'])->assertNotFound();
        $this->put(route('meal-plans.days.slots.reorder', [$plan, $day]), [
            'slot_ids' => $day->slots->pluck('id')->reverse()->values()->all(),
        ])->assertNotFound();

        $this->assertSame(1, $plan->days()->count());
        $this->assertSame('Breakfast', $slot->fresh()->name);
        $this->assertSame([0, 1, 2, 3, 4], $day->fresh()->slots->pluck('position')->all());
        $this->assertSame($owner->id, $plan->user_id);
    }

    public function test_guest_and_mismatched_nested_resources_cannot_reach_slot_mutations(): void
    {
        [$owner, $plan, $day] = $this->reusableDay();
        $otherPlan = MealPlan::factory()->for($owner, 'owner')->reusable()->create();
        $slot = $day->slots->firstOrFail();

        auth()->logout();
        $this->post(route('meal-plans.days.store', $plan), ['day_index' => 1])->assertRedirect(route('login'));
        $this->post(route('meal-plans.days.slots.store', [$plan, $day]), ['name' => 'Guest'])->assertRedirect(route('login'));
        $this->patch(route('meal-plans.days.slots.update', [$plan, $day, $slot]), ['name' => 'Guest'])->assertRedirect(route('login'));
        $this->put(route('meal-plans.days.slots.reorder', [$plan, $day]), ['slot_ids' => []])->assertRedirect(route('login'));

        $this->actingAs($owner)->patch(
            route('meal-plans.days.slots.update', [$otherPlan, $day, $slot]),
            ['name' => 'Wrong parent'],
        )->assertNotFound();
        $this->assertSame('Breakfast', $slot->fresh()->name);
    }

    public function test_plan_identity_cannot_be_changed_to_conflict_with_existing_days(): void
    {
        [$owner, $plan] = $this->reusableDay();

        $this->actingAs($owner)->patch(route('meal-plans.update', $plan), [
            'name' => $plan->name,
            'type' => MealPlanType::Dated->value,
            'starts_on' => '2026-10-05',
            'ends_on' => '2026-10-11',
        ])->assertRedirect()->assertSessionHasErrors('type');

        $this->assertSame(MealPlanType::Reusable, $plan->fresh()->type);
    }

    public function test_day_and_slot_factories_preserve_each_identity_shape(): void
    {
        $reusable = MealPlanDay::factory()->reusable(2)->create();
        $dated = MealPlanDay::factory()->dated('2026-09-19')->create();
        $slot = MealPlanSlot::factory()->for($reusable, 'day')->create();

        $this->assertSame(MealPlanType::Reusable, $reusable->mealPlan->type);
        $this->assertSame(2, $reusable->day_index);
        $this->assertNull($reusable->date);
        $this->assertSame(MealPlanType::Dated, $dated->mealPlan->type);
        $this->assertNull($dated->day_index);
        $this->assertSame('2026-09-19', $dated->date?->toDateString());
        $this->assertTrue($slot->day->is($reusable));
        $this->assertNull($slot->standard_key);
    }

    /** @return array{User, MealPlan, MealPlanDay} */
    private function reusableDay(): array
    {
        $owner = User::factory()->create();
        $plan = MealPlan::factory()->for($owner, 'owner')->reusable()->create();
        $this->actingAs($owner)->post(route('meal-plans.days.store', $plan), ['day_index' => 0])
            ->assertSessionHasNoErrors();

        return [$owner, $plan, $plan->days()->sole()];
    }
}
