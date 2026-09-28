<?php

namespace Tests\Feature\MealPlans;

use App\Domain\Measurements\StandardUnit;
use App\Domain\Nutrition\Nutrient;
use App\Domain\NutritionTargets\DailyNutritionComparison;
use App\Domain\NutritionTargets\MealPlanTargetPhaseManager;
use App\Models\MealPlan;
use App\Models\MealPlanDay;
use App\Models\MealPlanItemEntry;
use App\Models\MealPlanRecipeEntry;
use App\Models\MealPlanSlot;
use App\Models\NutritionTargetProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DailyNutritionComparisonTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function classifications(): array
    {
        return [
            'exact below' => ['exact', '9', '10', 'Below target'],
            'exact equal' => ['exact', '10', '10', 'At target'],
            'exact above even when display rounds equal' => ['exact', '10.004', '10', 'Above target'],
            'minimum below' => ['minimum', '9', '10', 'Below minimum'],
            'minimum equal' => ['minimum', '10', '10', 'Meets minimum'],
            'minimum above' => ['minimum', '11', '10', 'Meets minimum'],
            'maximum below' => ['maximum', '9', '10', 'Meets maximum'],
            'maximum equal' => ['maximum', '10', '10', 'Meets maximum'],
            'maximum above' => ['maximum', '11', '10', 'Above maximum'],
            'range below' => ['range', '9', '10', 'Below range'],
            'range lower inclusive' => ['range', '10', '10', 'Within range'],
            'range inside' => ['range', '12', '10', 'Within range'],
            'range upper inclusive' => ['range', '15', '10', 'Within range'],
            'range above' => ['range', '16', '10', 'Above range'],
        ];
    }

    #[DataProvider('classifications')]
    public function test_target_types_classify_using_full_precision(string $type, string $amount, string $boundary, string $expected): void
    {
        [$owner, $plan, $day, $slot] = $this->plan();
        $profile = NutritionTargetProfile::factory()->for($owner, 'owner')->create();
        $profile->targets()->create([
            'nutrient' => Nutrient::Protein->value, 'type' => $type,
            'exact_value' => $type === 'exact' ? $boundary : null,
            'minimum_value' => in_array($type, ['minimum', 'range'], true) ? $boundary : null,
            'maximum_value' => $type === 'maximum' ? $boundary : ($type === 'range' ? '15' : null),
        ]);
        app(MealPlanTargetPhaseManager::class)->create($owner, $plan, $profile, '2026-10-01', '2026-10-02');
        $this->recipe($slot, ['protein' => $this->fact($amount)]);

        $row = $this->row($owner, $plan, $day, 'Protein');
        $this->assertSame($expected, $row['planned']['status']);
        $this->assertSame('Not available', $row['consumed']['value']);
        $this->assertSame('Not available', $row['consumed']['status']);
    }

    public function test_phase_boundaries_and_historical_values_do_not_follow_profile_edits(): void
    {
        [$owner, $plan, $day, $slot] = $this->plan();
        $profile = NutritionTargetProfile::factory()->for($owner, 'owner')->create(['name' => 'Training']);
        $target = $profile->targets()->create(['nutrient' => 'protein', 'type' => 'exact', 'exact_value' => '10']);
        app(MealPlanTargetPhaseManager::class)->create($owner, $plan, $profile, '2026-10-01', '2026-10-01');
        $this->recipe($slot, ['protein' => $this->fact('10')]);
        $this->assertSame('At target', $this->row($owner, $plan, $day, 'Protein')['planned']['status']);

        $target->forceFill(['exact_value' => '99'])->save();
        $this->assertSame('Exact 10.0 g', $this->row($owner, $plan, $day, 'Protein')['target']);
        $this->assertSame('At target', $this->row($owner, $plan, $day, 'Protein')['planned']['status']);

        $second = MealPlanDay::factory()->for($plan)->create(['date' => '2026-10-02', 'day_index' => null]);
        $this->assertNull($this->comparison($owner, $plan, $second)['profile']);
        $this->assertSame('No target', $this->row($owner, $plan, $second, 'Protein')['target']);
        $this->actingAs($owner)->get(route('meal-plans.show', $plan))
            ->assertOk()->assertSee('Targets are personal planning guidance, not medical advice.')
            ->assertSee('Target phase: Training')->assertSee('No target phase applies on this date.');
    }

    public function test_planned_and_consumed_values_use_separate_pinned_snapshots_and_current_history(): void
    {
        [$owner, $plan, $day, $slot] = $this->plan();
        $entry = $this->recipe($slot, ['protein' => $this->fact('8', true)], '2.00');
        Date::setTestNow('2026-10-02 12:00:00 UTC');
        $this->actingAs($owner)->post(route('meal-plans.consumption.store', [$plan, 'recipe', $entry]), [
            'actual_amount' => '1', 'consumed_local_at' => '2026-10-01 12:00',
        ])->assertSessionHasNoErrors();
        $this->assertSame('16.0 g', $this->row($owner, $plan, $day, 'Protein')['planned']['value']);
        $this->assertSame('8.0 g', $this->row($owner, $plan, $day, 'Protein')['consumed']['value']);
        $this->assertSame('Estimate', $this->row($owner, $plan, $day, 'Protein')['consumed']['note']);

        $this->patch(route('meal-plans.consumption.update', [$plan, 'recipe', $entry]), ['actual_amount' => '0.5'])
            ->assertSessionHasNoErrors();
        $this->assertSame('4.0 g', $this->row($owner, $plan, $day, 'Protein')['consumed']['value']);
        $this->assertSame('16.0 g', $this->row($owner, $plan, $day, 'Protein')['planned']['value']);

        $this->delete(route('meal-plans.consumption.destroy', [$plan, 'recipe', $entry]))->assertSessionHasNoErrors();
        $this->assertSame('Not available', $this->row($owner, $plan, $day, 'Protein')['consumed']['value']);
    }

    public function test_all_supported_nutrients_use_their_own_target_and_display_definition(): void
    {
        [$owner, $plan, $day, $slot] = $this->plan();
        $profile = NutritionTargetProfile::factory()->for($owner, 'owner')->create();
        $facts = [];
        foreach (Nutrient::cases() as $nutrient) {
            $unit = str_starts_with($nutrient->value, 'energy_') ? substr($nutrient->value, 7) : 'g';
            $facts[$nutrient->value] = ['value' => $nutrient === Nutrient::EnergyKj ? '8.368' : '2', 'unit' => $unit, 'basis' => 'per_serving', 'status' => 'known'];
            $profile->targets()->create(['nutrient' => $nutrient->value, 'type' => 'exact', 'exact_value' => '2']);
        }
        app(MealPlanTargetPhaseManager::class)->create($owner, $plan, $profile, '2026-10-01', '2026-10-01');
        $this->recipe($slot, $facts);
        $rows = $this->comparison($owner, $plan, $day)['rows'];
        $this->assertCount(count(Nutrient::cases()), $rows);
        foreach ($rows as $row) {
            $this->assertSame('At target', $row['planned']['status']);
        }
        $this->assertSame('2000 mg', $rows[array_key_last($rows)]['planned']['value']);
    }

    public function test_item_basis_conversion_and_ad_hoc_diary_intake_are_included_without_planned_substitution(): void
    {
        [$owner, $plan, $day, $slot] = $this->plan();
        MealPlanItemEntry::factory()->for($slot, 'slot')->create([
            'planned_amount' => '250', 'planned_unit' => StandardUnit::Gram,
            'one_off_nutrition' => ['basis' => 'per_100g', 'values' => [
                'protein' => ['value' => '2', 'unit' => 'g', 'basis' => 'per_100g', 'status' => 'known'],
            ]],
        ]);
        MealPlanItemEntry::factory()->for($slot, 'slot')->create([
            'planned_amount' => '1', 'planned_unit' => StandardUnit::Item,
            'one_off_nutrition' => ['basis' => 'per_100g', 'values' => [
                'protein' => ['value' => '10', 'unit' => 'g', 'basis' => 'per_100g', 'status' => 'known'],
            ]],
        ]);
        $this->assertSame('5.0 g', $this->row($owner, $plan, $day, 'Protein')['planned']['value']);
        $this->assertStringContainsString('Partial total', $this->row($owner, $plan, $day, 'Protein')['planned']['note']);
        $this->assertSame('Not available', $this->row($owner, $plan, $day, 'Protein')['consumed']['value']);

        Date::setTestNow('2026-10-02 12:00:00 UTC');
        $this->actingAs($owner)->post(route('diary-entries.store'), [
            'kind' => 'one_off', 'one_off_wording' => 'After dinner',
            'actual_amount' => '2', 'actual_unit' => StandardUnit::Serving->value,
            'one_off_nutrition_basis' => 'per_serving', 'one_off_nutrition' => ['protein' => '3'],
            'consumed_local_at' => '2026-10-01 20:00', 'effective_diary_date' => '2026-10-01',
        ])->assertSessionHasNoErrors();
        $this->assertSame('5.0 g', $this->row($owner, $plan, $day, 'Protein')['planned']['value']);
        $this->assertSame('6.0 g', $this->row($owner, $plan, $day, 'Protein')['consumed']['value']);
        $this->assertSame('Estimate', $this->row($owner, $plan, $day, 'Protein')['consumed']['note']);
    }

    public function test_missing_partial_and_zero_are_distinct_and_private(): void
    {
        [$owner, $plan, $day, $slot] = $this->plan();
        $profile = NutritionTargetProfile::factory()->for($owner, 'owner')->create();
        $profile->targets()->create(['nutrient' => 'protein', 'type' => 'minimum', 'minimum_value' => '1']);
        app(MealPlanTargetPhaseManager::class)->create($owner, $plan, $profile, '2026-10-01', '2026-10-01');
        $this->recipe($slot, ['protein' => $this->fact('0'), 'fat' => $this->fact('2')]);
        $this->recipe($slot, ['fat' => $this->fact('3')]);
        $this->assertSame('0.0 g', $this->row($owner, $plan, $day, 'Protein')['planned']['value']);
        $this->assertStringContainsString('Partial total', $this->row($owner, $plan, $day, 'Protein')['planned']['note']);
        $this->assertSame('Comparison unavailable', $this->row($owner, $plan, $day, 'Protein')['planned']['status']);
        $this->assertSame('5.0 g', $this->row($owner, $plan, $day, 'Fat')['planned']['value']);
        $this->assertSame('Not available', $this->row($owner, $plan, $day, 'Fibre')['planned']['value']);
        $this->assertSame('No target', $this->row($owner, $plan, $day, 'Fat')['target']);

        $other = User::factory()->create();
        $this->actingAs($other)->get(route('meal-plans.show', $plan))->assertNotFound();
    }

    /** @return array{User, MealPlan, MealPlanDay, MealPlanSlot} */
    private function plan(): array
    {
        Date::setTestNow('2026-09-28 12:00:00 UTC');
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $plan = MealPlan::factory()->for($owner, 'owner')->dated()->create(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-02']);
        $day = MealPlanDay::factory()->for($plan)->create(['date' => '2026-10-01', 'day_index' => null]);
        $slot = MealPlanSlot::factory()->for($day, 'day')->create();

        return [$owner, $plan, $day, $slot];
    }

    /** @param array<string, array<string, mixed>> $facts */
    private function recipe(MealPlanSlot $slot, array $facts, string $servings = '1.00'): MealPlanRecipeEntry
    {
        return MealPlanRecipeEntry::factory()->for($slot, 'slot')->create([
            'planned_servings' => $servings,
            'nutrition_snapshot' => ['source' => 'ingredient_estimate', 'values' => $facts, 'provenance' => null, 'ingredient_estimate' => []],
        ]);
    }

    /** @return array<string, mixed> */
    private function fact(string $value, bool $estimate = false): array
    {
        return ['value' => $value, 'unit' => 'g', 'basis' => 'per_serving',
            'status' => $estimate ? 'approximate' : 'known', 'is_estimate' => $estimate];
    }

    /** @return array{profile: string|null, rows: list<array<string, mixed>>} */
    private function comparison(User $owner, MealPlan $plan, MealPlanDay $day): array
    {
        $day->load('slots.recipeEntries', 'slots.itemEntries');

        return app(DailyNutritionComparison::class)->forDay($owner, $plan, $day);
    }

    /** @return array<string, mixed> */
    private function row(User $owner, MealPlan $plan, MealPlanDay $day, string $label): array
    {
        foreach ($this->comparison($owner, $plan, $day)['rows'] as $row) {
            if ($row['label'] === $label) {
                return $row;
            }
        }
        $this->fail('Missing nutrient row '.$label);
    }
}
