<?php

namespace App\Domain\AccountDeletion;

use App\Models\MealPlan;
use Illuminate\Support\Facades\DB;

final class PublicPlanSnapshotProtection
{
    /** @return array<string, array<string, string>> */
    public function protect(MealPlan $plan): array
    {
        $prior = [];
        $plan->load(['days.slots.recipeEntries', 'days.slots.itemEntries']);
        foreach ($plan->days as $day) {
            foreach ($day->slots as $slot) {
                foreach ($slot->recipeEntries as $entry) {
                    $prior['recipe:'.$entry->id] = [
                        'recipe_snapshot' => $entry->getRawOriginal('recipe_snapshot'),
                        'nutrition_snapshot' => $entry->getRawOriginal('nutrition_snapshot'),
                    ];
                    DB::table('meal_plan_recipe_entries')->where('id', $entry->id)->update([
                        'recipe_snapshot' => json_encode(app(PublicContentMinimizer::class)->recipe($entry->recipe_snapshot), JSON_THROW_ON_ERROR),
                        'nutrition_snapshot' => json_encode(app(PublicContentMinimizer::class)->nutrition($entry->nutrition_snapshot), JSON_THROW_ON_ERROR),
                    ]);
                }
                foreach ($slot->itemEntries as $entry) {
                    $prior['item:'.$entry->id] = ['catalogue_snapshot' => $entry->getRawOriginal('catalogue_snapshot')];
                    DB::table('meal_plan_item_entries')->where('id', $entry->id)->update([
                        'catalogue_snapshot' => json_encode(app(PublicContentMinimizer::class)->catalogue($entry->catalogue_snapshot), JSON_THROW_ON_ERROR),
                    ]);
                }
            }
        }

        return $prior;
    }

    /** @param array<string, array<string, string>> $snapshots */
    public function restore(MealPlan $plan, array $snapshots): void
    {
        $slots = DB::table('meal_plan_slots')->whereIn('meal_plan_day_id',
            DB::table('meal_plan_days')->where('meal_plan_id', $plan->id)->select('id'))->select('id');
        foreach ($snapshots as $key => $fields) {
            [$kind, $id] = explode(':', $key, 2);
            $table = $kind === 'recipe' ? 'meal_plan_recipe_entries' : 'meal_plan_item_entries';
            $allowed = $kind === 'recipe' ? ['recipe_snapshot', 'nutrition_snapshot'] : ['catalogue_snapshot'];
            DB::table($table)->where('id', $id)->whereIn('meal_plan_slot_id', $slots)->update(array_intersect_key($fields, array_flip($allowed)));
        }
    }

    /** Public presentation and copy facts only; no owner, consumption, target or operational timestamps. */
    public function fingerprint(MealPlan $plan): string
    {
        $plan->load(['days.slots.recipeEntries', 'days.slots.itemEntries']);
        $data = [$plan->name, $plan->type->value, $plan->starts_on?->toDateString(), $plan->ends_on?->toDateString(), []];
        foreach ($plan->days as $day) {
            $dayData = [$day->day_index, $day->date?->toDateString(), []];
            foreach ($day->slots as $slot) {
                $slotData = [$slot->standard_key, $slot->name, $slot->position, [], []];
                foreach ($slot->recipeEntries as $entry) {
                    $slotData[3][] = [$entry->recipe_id, $entry->recipe_version_id, $entry->recipe_version_number,
                        $entry->planned_servings, app(PublicContentMinimizer::class)->recipe($entry->recipe_snapshot),
                        app(PublicContentMinimizer::class)->nutrition($entry->nutrition_snapshot)];
                }
                foreach ($slot->itemEntries as $entry) {
                    $slotData[4][] = [$entry->kind->value, $entry->planned_amount, $entry->planned_unit->value,
                        $entry->catalogue_item_id, $entry->catalogue_item_version_id, $entry->catalogue_item_version_number,
                        app(PublicContentMinimizer::class)->catalogue($entry->catalogue_snapshot), $entry->catalogue_nutrition_snapshot];
                }
                $dayData[2][] = $slotData;
            }
            $data[4][] = $dayData;
        }

        return hash('sha256', json_encode($this->sort($data), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function sort(array $value): array
    {
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = $this->sort($child);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
