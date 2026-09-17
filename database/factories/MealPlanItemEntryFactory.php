<?php

namespace Database\Factories;

use App\Domain\MealPlans\MealPlanItemEntryKind;
use App\Domain\Measurements\StandardUnit;
use App\Models\MealPlanItemEntry;
use App\Models\MealPlanSlot;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MealPlanItemEntry> */
class MealPlanItemEntryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'meal_plan_slot_id' => MealPlanSlot::factory(),
            'kind' => MealPlanItemEntryKind::OneOff,
            'planned_amount' => '1',
            'planned_unit' => StandardUnit::Serving,
            'catalogue_item_id' => null,
            'catalogue_item_version_id' => null,
            'catalogue_item_version_number' => null,
            'catalogue_snapshot' => null,
            'catalogue_nutrition_snapshot' => null,
            'one_off_wording' => fake()->words(3, true),
            'one_off_nutrition' => null,
        ];
    }
}
