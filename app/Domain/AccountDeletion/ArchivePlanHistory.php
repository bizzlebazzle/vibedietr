<?php

namespace App\Domain\AccountDeletion;

use App\Models\AccountDeletion;
use App\Models\MealPlan;
use App\Models\PrivateConsumptionArchive;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ArchivePlanHistory
{
    /** Called with the plan locked, in the same transaction as permanent removal. */
    public function preserve(MealPlan $plan, ?CarbonImmutable $asOf = null): void
    {
        $owner = null;
        foreach (AccountDeletion::query()->where('reason', 'ordinary')->where('deadline', '>', $asOf ?? now()->utc())->cursor() as $request) {
            $prior = $request->recovery_state['plans'][(string) $plan->getKey()] ?? null;
            if (($prior['generation'] ?? null) === $plan->erasure_generation) {
                $owner = $request->user_id;
                break;
            }
        }
        if ($owner !== null) {
            $this->capture($plan, (int) $owner);
        }
    }

    /** The caller supplies a verified historical request, not a new recovery authorization. */
    public function preserveForReplay(MealPlan $plan, User $owner, array $request, CarbonImmutable $removedAt): void
    {
        app(PrivacyOperator::class)->reference();
        if ($request['state'] !== 'requested' || $request['account_generation'] !== $owner->account_generation
            || ! in_array('plan_'.$plan->erasure_generation, $request['resources'], true)
            || CarbonImmutable::parse($request['deadline'])->lessThanOrEqualTo($removedAt)
            || ($plan->user_id !== null && (int) $plan->user_id !== (int) $owner->getKey())) {
            return;
        }
        $this->capture($plan, (int) $owner->getKey());
    }

    private function capture(MealPlan $plan, int $owner): void
    {
        if (PrivateConsumptionArchive::query()->where('source_plan_generation', $plan->erasure_generation)->exists()) {
            return;
        }
        $days = DB::table('meal_plan_days')->where('meal_plan_id', $plan->getKey())->pluck('id');
        $slots = DB::table('meal_plan_slots')->whereIn('meal_plan_day_id', $days)->pluck('id');
        $recipes = DB::table('meal_plan_recipe_entries')->whereIn('meal_plan_slot_id', $slots)->pluck('id');
        $items = DB::table('meal_plan_item_entries')->whereIn('meal_plan_slot_id', $slots)->pluck('id');
        $states = DB::table('diary_consumption_states')->whereIn('meal_plan_recipe_entry_id', $recipes)
            ->orWhereIn('meal_plan_item_entry_id', $items)->get();
        $phases = DB::table('meal_plan_target_phases')->where('meal_plan_id', $plan->getKey())->get();
        if ($states->isEmpty() && $phases->isEmpty()) {
            return;
        }
        $stateIds = $states->pluck('id');
        $recipeIds = $states->pluck('meal_plan_recipe_entry_id')->filter();
        $itemIds = $states->pluck('meal_plan_item_entry_id')->filter();
        $rows = fn ($query): array => $query->get()->map(fn ($row): array => (array) $row)->all();
        $archive = new PrivateConsumptionArchive;
        $archive->forceFill([
            'user_id' => $owner, 'source_plan_generation' => $plan->erasure_generation,
            'history' => [
                'name' => $plan->name,
                'days' => $rows(DB::table('meal_plan_days')->whereIn('id', $days)),
                'slots' => $rows(DB::table('meal_plan_slots')->whereIn('id', $slots)),
                'recipe_entries' => $rows(DB::table('meal_plan_recipe_entries')->whereIn('id', $recipeIds)),
                'item_entries' => $rows(DB::table('meal_plan_item_entries')->whereIn('id', $itemIds)),
                'states' => $states->map(fn ($row): array => (array) $row)->all(),
                'transitions' => $rows(DB::table('diary_consumption_transitions')->whereIn('diary_consumption_state_id', $stateIds)->orderBy('diary_consumption_state_id')->orderBy('sequence')),
                'nutrition_snapshots' => $rows(DB::table('consumption_nutrition_snapshots')->whereIn('diary_consumption_state_id', $stateIds)),
                'phases' => $phases->map(fn ($row): array => (array) $row)->all(),
                'phase_values' => $rows(DB::table('meal_plan_target_phase_values')->whereIn('meal_plan_target_phase_id', $phases->pluck('id'))),
            ],
        ])->save();
        // Snapshots restrict transition deletion; keep the original immutable rows
        // in the encrypted archive before removing their relational graph.
        DB::table('diary_consumption_transitions')->whereIn('diary_consumption_state_id', $stateIds)->delete();
        DB::table('diary_consumption_states')->whereIn('id', $stateIds)->delete();
    }
}
