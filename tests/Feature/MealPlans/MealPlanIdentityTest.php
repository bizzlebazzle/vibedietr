<?php

namespace Tests\Feature\MealPlans;

use App\Domain\MealPlans\MealPlanType;
use App\Domain\MealPlans\MealPlanVisibility;
use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class MealPlanIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_both_plan_types_and_new_plans_default_private(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->post(route('meal-plans.store'), [
            'name' => '  Weekday rhythm  ',
            'type' => MealPlanType::Reusable->value,
        ])->assertRedirect();

        $this->post(route('meal-plans.store'), [
            'name' => 'October week',
            'type' => MealPlanType::Dated->value,
            'starts_on' => '2026-10-05',
            'ends_on' => '2026-10-11',
        ])->assertRedirect();

        $reusable = MealPlan::query()->where('type', MealPlanType::Reusable)->sole();
        $dated = MealPlan::query()->where('type', MealPlanType::Dated)->sole();

        $this->assertSame($owner->id, $reusable->user_id);
        $this->assertSame('Weekday rhythm', $reusable->name);
        $this->assertSame(MealPlanVisibility::Private, $reusable->visibility);
        $this->assertNull($reusable->starts_on);
        $this->assertNull($reusable->ends_on);
        $this->assertSame($owner->id, $dated->user_id);
        $this->assertSame(MealPlanVisibility::Private, $dated->visibility);
        $this->assertSame('2026-10-05', $dated->starts_on?->format('Y-m-d'));
        $this->assertSame('2026-10-11', $dated->ends_on?->format('Y-m-d'));
    }

    public function test_invalid_type_and_date_combinations_are_rejected_without_normalization(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $invalidPlans = [
            ['type' => 'weekly', 'starts_on' => null, 'ends_on' => null],
            ['type' => 'reusable', 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-11'],
            ['type' => 'dated', 'starts_on' => null, 'ends_on' => null],
            ['type' => 'dated', 'starts_on' => '2026-10-05', 'ends_on' => null],
            ['type' => 'dated', 'starts_on' => '2026-10-11', 'ends_on' => '2026-10-05'],
        ];

        foreach ($invalidPlans as $index => $attributes) {
            $this->post(route('meal-plans.store'), [
                'name' => 'Invalid '.$index,
                ...$attributes,
            ])->assertSessionHasErrors();
        }

        $this->assertDatabaseCount('meal_plans', 0);
    }

    public function test_database_rejects_an_invalid_type_date_combination(): void
    {
        $owner = User::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('meal_plans')->insert([
            'user_id' => $owner->id,
            'name' => 'Invalid reusable plan',
            'type' => MealPlanType::Reusable->value,
            'visibility' => MealPlanVisibility::Private->value,
            'starts_on' => '2026-10-05',
            'ends_on' => '2026-10-11',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_owner_can_view_and_edit_a_plan(): void
    {
        $owner = User::factory()->create();
        $mealPlan = MealPlan::factory()->for($owner, 'owner')->reusable()->create(['name' => 'Template']);

        $this->actingAs($owner)->get(route('meal-plans.show', $mealPlan))
            ->assertOk()->assertSee('Template')->assertSee('Reusable undated schedule');
        $this->get(route('meal-plans.edit', $mealPlan))->assertOk();
        $this->patch(route('meal-plans.update', $mealPlan), [
            'name' => 'Dated week',
            'type' => MealPlanType::Dated->value,
            'starts_on' => '2026-11-02',
            'ends_on' => '2026-11-08',
        ])->assertRedirect(route('meal-plans.show', $mealPlan));

        $mealPlan->refresh();
        $this->assertSame('Dated week', $mealPlan->name);
        $this->assertSame(MealPlanType::Dated, $mealPlan->type);
        $this->assertSame($owner->id, $mealPlan->user_id);
    }

    public function test_non_owner_cannot_discover_view_or_edit_a_private_plan(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $mealPlan = MealPlan::factory()->for($owner, 'owner')->create(['name' => 'Owner secret']);

        $this->actingAs($other)->get(route('meal-plans.index'))
            ->assertOk()->assertDontSee('Owner secret');
        $this->get(route('meal-plans.show', $mealPlan))->assertNotFound();
        $this->get(route('meal-plans.edit', $mealPlan))->assertNotFound();
        $this->patch(route('meal-plans.update', $mealPlan), [
            'name' => 'Forged',
            'type' => MealPlanType::Reusable->value,
        ])->assertNotFound();

        $this->assertFalse(Gate::forUser($other)->allows('view', $mealPlan));
        $this->assertFalse(Gate::forUser($other)->allows('update', $mealPlan));
        $this->assertSame('Owner secret', $mealPlan->fresh()->name);
    }

    public function test_guest_is_redirected_from_every_plan_boundary(): void
    {
        $mealPlan = MealPlan::factory()->create();

        $this->get(route('meal-plans.index'))->assertRedirect(route('login'));
        $this->get(route('meal-plans.create'))->assertRedirect(route('login'));
        $this->post(route('meal-plans.store'), [])->assertRedirect(route('login'));
        $this->get(route('meal-plans.show', $mealPlan))->assertRedirect(route('login'));
        $this->get(route('meal-plans.edit', $mealPlan))->assertRedirect(route('login'));
        $this->patch(route('meal-plans.update', $mealPlan), [])->assertRedirect(route('login'));
    }

    public function test_submitted_owner_and_visibility_are_rejected(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($owner)->post(route('meal-plans.store'), [
            'name' => 'Forged plan',
            'type' => MealPlanType::Reusable->value,
            'user_id' => $other->id,
            'visibility' => 'public',
        ])->assertSessionHasErrors(['user_id', 'visibility']);

        $this->assertDatabaseCount('meal_plans', 0);
    }

    public function test_factory_states_preserve_one_owner_type_privacy_and_date_semantics(): void
    {
        $owner = User::factory()->create();
        $reusable = MealPlan::factory()->for($owner, 'owner')->reusable()->create();
        $dated = MealPlan::factory()->for($owner, 'owner')->dated()->create();

        $this->assertTrue($reusable->owner->is($owner));
        $this->assertTrue($owner->mealPlans->contains($reusable));
        $this->assertSame(MealPlanType::Reusable, $reusable->type);
        $this->assertSame(MealPlanVisibility::Private, $reusable->visibility);
        $this->assertNull($reusable->starts_on);
        $this->assertNull($reusable->ends_on);
        $this->assertSame(MealPlanType::Dated, $dated->type);
        $this->assertSame(MealPlanVisibility::Private, $dated->visibility);
        $this->assertNotNull($dated->starts_on);
        $this->assertNotNull($dated->ends_on);
        $this->assertTrue($dated->ends_on->greaterThanOrEqualTo($dated->starts_on));
    }
}
