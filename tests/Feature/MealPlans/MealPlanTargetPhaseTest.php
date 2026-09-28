<?php

namespace Tests\Feature\MealPlans;

use App\Domain\MealPlans\MealPlanVisibility;
use App\Domain\Nutrition\Nutrient;
use App\Domain\NutritionTargets\MealPlanTargetPhaseManager;
use App\Models\MealPlan;
use App\Models\NutritionTargetProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class MealPlanTargetPhaseTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_open_and_closed_phases_resolve_boundaries_and_leave_gaps_unassigned(): void
    {
        Carbon::setTestNow('2026-09-28 12:00:00');
        [$owner, $plan, $profile] = $this->fixture();
        $manager = app(MealPlanTargetPhaseManager::class);
        $this->actingAs($owner);

        $this->post(route('meal-plans.target-phases.store', $plan), [
            'profile_id' => $profile->id,
            'starts_on' => '2026-10-01',
            'ends_on' => '2026-10-03',
        ])->assertRedirect();

        $this->post(route('meal-plans.target-phases.store', $plan), [
            'profile_id' => $profile->id,
            'starts_on' => '2026-10-05',
        ])->assertRedirect();

        $this->assertNull($manager->resolve($owner, $plan, '2026-09-30'));
        $this->assertSame('2000.000000000000000000', $this->value($owner, $plan, '2026-10-01'));
        $this->assertSame('2000.000000000000000000', $this->value($owner, $plan, '2026-10-03'));
        $this->assertNull($manager->resolve($owner, $plan, '2026-10-04'));
        $this->assertSame('2000.000000000000000000', $this->value($owner, $plan, '2026-10-05'));
        $this->assertSame('2000.000000000000000000', $this->value($owner, $plan, '2026-10-10'));
        $this->assertNull($manager->resolve($owner, $plan, '2026-10-11'));
    }

    public function test_inclusive_overlap_is_rejected_without_changing_existing_phase(): void
    {
        Carbon::setTestNow('2026-09-28 12:00:00');
        [$owner, $plan, $profile] = $this->fixture();
        $this->actingAs($owner);

        $this->post(route('meal-plans.target-phases.store', $plan), [
            'profile_id' => $profile->id, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-03',
        ])->assertRedirect();

        $this->post(route('meal-plans.target-phases.store', $plan), [
            'profile_id' => $profile->id, 'starts_on' => '2026-10-03', 'ends_on' => '2026-10-04',
        ])->assertSessionHasErrors('starts_on');

        $this->assertSame(1, $plan->targetPhases()->count());
    }

    public function test_profile_edits_change_future_dates_only_and_same_day_edits_replace_pending_version(): void
    {
        Carbon::setTestNow('2026-09-28 12:00:00');
        [$owner, $plan, $profile] = $this->fixture('2026-09-25');
        $this->actingAs($owner);

        $this->post(route('meal-plans.target-phases.store', $plan), [
            'profile_id' => $profile->id, 'starts_on' => '2026-09-25',
        ])->assertRedirect();
        $this->assertSame('2000.000000000000000000', $this->value($owner, $plan, '2026-09-27'));
        $this->assertNull(app(MealPlanTargetPhaseManager::class)->resolve($owner, $plan, '2026-09-28'));

        $this->put(route('nutrition-target-profiles.update', $profile), $this->targetInput('2200'))->assertRedirect();
        $this->put(route('nutrition-target-profiles.update', $profile), $this->targetInput('2300'))->assertRedirect();

        $this->assertSame('2000.000000000000000000', $this->value($owner, $plan, '2026-09-27'));
        $this->assertSame('2300.000000000000000000', $this->value($owner, $plan, '2026-09-29'));

        Carbon::setTestNow('2026-09-29 12:00:00');
        $this->put(route('nutrition-target-profiles.update', $profile), $this->targetInput('2400'))->assertRedirect();
        $this->assertSame('2300.000000000000000000', $this->value($owner, $plan, '2026-09-29'));
        $this->assertSame('2400.000000000000000000', $this->value($owner, $plan, '2026-09-30'));
    }

    public function test_phase_edit_and_profile_delete_preserve_past_and_clear_future(): void
    {
        Carbon::setTestNow('2026-09-28 12:00:00');
        [$owner, $plan, $profile] = $this->fixture('2026-09-25');
        $otherProfile = NutritionTargetProfile::factory()->for($owner, 'owner')->create(['name' => 'Rest']);
        $otherProfile->targets()->create([
            'nutrient' => Nutrient::EnergyKcal->value, 'type' => 'exact', 'exact_value' => '1800',
        ]);
        $this->actingAs($owner);

        $this->post(route('meal-plans.target-phases.store', $plan), [
            'profile_id' => $profile->id, 'starts_on' => '2026-09-25',
        ])->assertRedirect();
        $future = $plan->targetPhases()->whereDate('starts_on', '2026-09-29')->sole();

        $this->patch(route('meal-plans.target-phases.update', [$plan, $future]), [
            'profile_id' => $otherProfile->id, 'starts_on' => '2026-09-29',
        ])->assertRedirect();

        $this->assertSame('2000.000000000000000000', $this->value($owner, $plan, '2026-09-27'));
        $this->assertSame('1800.000000000000000000', $this->value($owner, $plan, '2026-09-29'));

        $this->delete(route('nutrition-target-profiles.destroy', $otherProfile))->assertRedirect();
        $this->assertSame('2000.000000000000000000', $this->value($owner, $plan, '2026-09-27'));
        $this->assertNull(app(MealPlanTargetPhaseManager::class)->resolve($owner, $plan, '2026-09-29'));
    }

    public function test_backfill_uses_current_values_and_rejects_overlap_with_existing_history(): void
    {
        Carbon::setTestNow('2026-09-28 12:00:00');
        [$owner, $plan, $profile] = $this->fixture('2026-09-25');
        $this->actingAs($owner);
        $this->put(route('nutrition-target-profiles.update', $profile), $this->targetInput('2500'))->assertRedirect();

        $this->post(route('meal-plans.target-phases.store', $plan), [
            'profile_id' => $profile->id, 'starts_on' => '2026-09-25', 'ends_on' => '2026-09-27',
        ])->assertRedirect();
        $this->assertSame('2500.000000000000000000', $this->value($owner, $plan, '2026-09-25'));

        $this->post(route('meal-plans.target-phases.store', $plan), [
            'profile_id' => $profile->id, 'starts_on' => '2026-09-27', 'ends_on' => '2026-09-29',
        ])->assertSessionHasErrors('starts_on');
    }

    public function test_editing_and_removing_an_active_phase_keep_today_and_past_unchanged(): void
    {
        Carbon::setTestNow('2026-09-25 12:00:00');
        [$owner, $plan, $profile] = $this->fixture('2026-09-25');
        $replacement = NutritionTargetProfile::factory()->for($owner, 'owner')->create(['name' => 'Recovery']);
        $replacement->targets()->create([
            'nutrient' => Nutrient::EnergyKcal->value, 'type' => 'exact', 'exact_value' => '1700',
        ]);
        $this->actingAs($owner)->post(route('meal-plans.target-phases.store', $plan), [
            'profile_id' => $profile->id, 'starts_on' => '2026-09-25',
        ])->assertRedirect();
        $phase = $plan->targetPhases()->sole();

        Carbon::setTestNow('2026-09-28 12:00:00');
        $this->patch(route('meal-plans.target-phases.update', [$plan, $phase]), [
            'profile_id' => $replacement->id, 'starts_on' => '2026-09-25',
        ])->assertRedirect();
        $this->assertSame('2000.000000000000000000', $this->value($owner, $plan, '2026-09-28'));
        $this->assertSame('1700.000000000000000000', $this->value($owner, $plan, '2026-09-29'));

        $future = $plan->targetPhases()->whereDate('starts_on', '2026-09-29')->sole();
        $this->delete(route('meal-plans.target-phases.destroy', [$plan, $future]))->assertRedirect();
        $this->assertSame('2000.000000000000000000', $this->value($owner, $plan, '2026-09-28'));
        $this->assertNull(app(MealPlanTargetPhaseManager::class)->resolve($owner, $plan, '2026-09-29'));
    }

    public function test_a_current_day_only_assignment_is_rejected_without_a_silent_noop(): void
    {
        Carbon::setTestNow('2026-09-28 12:00:00');
        [$owner, $plan, $profile] = $this->fixture();
        $this->actingAs($owner)->post(route('meal-plans.target-phases.store', $plan), [
            'profile_id' => $profile->id,
            'starts_on' => '2026-09-28',
            'ends_on' => '2026-09-28',
        ])->assertSessionHasErrors('starts_on');
        $this->assertSame(0, $plan->targetPhases()->count());
    }

    public function test_reusable_plans_reject_phases_and_public_views_do_not_expose_targets(): void
    {
        Carbon::setTestNow('2026-09-28 12:00:00');
        [$owner, $plan, $profile] = $this->fixture();
        $reusable = MealPlan::factory()->for($owner, 'owner')->reusable()->create();
        $payload = ['profile_id' => $profile->id, 'starts_on' => '2026-10-01'];

        $this->actingAs($owner)->post(route('meal-plans.target-phases.store', $reusable), $payload)
            ->assertSessionHasErrors('meal_plan');
        $this->post(route('meal-plans.target-phases.store', $plan), $payload)->assertRedirect();

        $plan->forceFill(['visibility' => MealPlanVisibility::Public])->save();
        auth()->logout();

        $this->get(route('meal-plans.show', $plan))->assertOk()
            ->assertDontSee('Training')->assertDontSee('2000.000000000000000000');
    }

    public function test_nonowners_and_guests_cannot_assign_modify_or_resolve_private_targets(): void
    {
        Carbon::setTestNow('2026-09-28 12:00:00');
        [$owner, $plan, $profile] = $this->fixture();
        $other = User::factory()->administrator()->create();
        $otherProfile = NutritionTargetProfile::factory()->for($other, 'owner')->create();
        $payload = ['profile_id' => $profile->id, 'starts_on' => '2026-10-01'];
        $route = route('meal-plans.target-phases.store', $plan);

        $this->post($route, $payload)->assertRedirect(route('login'));
        $this->actingAs($other)->post($route, $payload)->assertNotFound();
        $this->actingAs($owner)->post($route, [
            'profile_id' => $otherProfile->id, 'starts_on' => '2026-10-01',
        ])->assertNotFound();
        $this->post($route, $payload)->assertRedirect();
        $phase = $plan->targetPhases()->sole();

        $this->actingAs($other)->patch(route('meal-plans.target-phases.update', [$plan, $phase]), $payload)->assertNotFound();
        $this->delete(route('meal-plans.target-phases.destroy', [$plan, $phase]))->assertNotFound();
        $this->expectException(NotFoundHttpException::class);
        app(MealPlanTargetPhaseManager::class)->resolve($other, $plan, '2026-10-01');
    }

    /** @return array{User, MealPlan, NutritionTargetProfile} */
    private function fixture(string $startsOn = '2026-09-28'): array
    {
        $owner = User::factory()->create();
        $plan = MealPlan::factory()->for($owner, 'owner')->dated()->create([
            'starts_on' => $startsOn, 'ends_on' => '2026-10-10',
        ]);
        $profile = NutritionTargetProfile::factory()->for($owner, 'owner')->create(['name' => 'Training']);
        $profile->targets()->create([
            'nutrient' => Nutrient::EnergyKcal->value, 'type' => 'exact', 'exact_value' => '2000',
        ]);

        return [$owner, $plan, $profile];
    }

    /** @return array<string, mixed> */
    private function targetInput(string $value): array
    {
        return ['name' => 'Training', 'targets' => [
            Nutrient::EnergyKcal->value => ['type' => 'exact', 'exact_value' => $value],
        ]];
    }

    private function value(User $owner, MealPlan $plan, string $date): ?string
    {
        return data_get(app(MealPlanTargetPhaseManager::class)->resolve($owner, $plan, $date), 'targets.energy_kcal.exact_value');
    }
}
