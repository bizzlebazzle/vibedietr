<?php

namespace App\Domain\AccountDeletion;

use App\Models\AccountExport;
use App\Models\PrivateConsumptionArchive;
use App\Models\Recipe;
use App\Models\RecipeImport;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ErasureResourceInventory
{
    /** Only opaque references and recovery visibility; never content or object keys.
     * @return list<string>
     */
    public function forUser(User $user): array
    {
        $resources = [];
        foreach (DB::table('audit_actor_identities')->where('user_id', $user->getKey())->pluck('id') as $id) {
            $resources[] = 'identity_'.$id;
        }
        $plans = $user->mealPlans()->get();
        foreach ($plans as $plan) {
            $resources[] = 'plan_'.$plan->erasure_generation;
            $resources[] = 'visibility_'.$plan->visibility->value.'_'.$plan->erasure_generation;
        }
        foreach (Recipe::query()->where('user_id', $user->getKey())->get() as $recipe) {
            $resources[] = 'recipe_'.$recipe->erasure_generation;
        }
        foreach (RecipeImport::query()->where('user_id', $user->getKey())->cursor() as $import) {
            $resources[] = 'import_'.$import->getKey();
            foreach (['source', 'canonical'] as $kind) {
                if ($import->{$kind.'_key'} !== null) {
                    $resources[] = 'object_'.hash('sha256', $import->{$kind.'_disk'}.'|'.$import->{$kind.'_key'});
                }
            }
        }
        foreach (AccountExport::query()->where('user_id', $user->getKey())->cursor() as $export) {
            $resources[] = 'export_'.$export->getKey();
            $resources[] = 'object_'.hash('sha256', $export->disk.'|'.$export->storageKey());
        }
        $slots = DB::table('meal_plan_slots')->whereIn('meal_plan_day_id',
            DB::table('meal_plan_days')->whereIn('meal_plan_id', $plans->modelKeys())->select('id'))->select('id');
        $entries = DB::table('meal_plan_recipe_entries')->whereIn('meal_plan_slot_id', $slots)->pluck('id');
        $items = DB::table('meal_plan_item_entries')->whereIn('meal_plan_slot_id', $slots)->pluck('id');
        $states = DB::table('diary_consumption_states')->whereIn('meal_plan_recipe_entry_id', $entries)
            ->orWhereIn('meal_plan_item_entry_id', $items)->orWhereIn('diary_entry_id',
                DB::table('diary_entries')->where('user_id', $user->getKey())->select('id'))->pluck('id');
        $versions = DB::table('recipe_versions')->whereIn('recipe_id', DB::table('recipes')->where('user_id', $user->getKey())->select('id'))->select('id');
        $recalculations = DB::table('recipe_nutrition_recalculations')->whereIn('recipe_version_id', $versions)->get();
        foreach ($recalculations as $calculation) {
            $resources[] = 'recalculation_'.$calculation->id;
        }
        $audits = [
            ...$recalculations->pluck('audit_event_id')->filter()->all(),
            ...DB::table('recipe_nutrition_override_events')->whereIn('recipe_version_id', $versions)->pluck('audit_event_id')->all(),
            ...DB::table('diary_consumption_transitions')->whereIn('diary_consumption_state_id', $states)->pluck('audit_event_id')->all(),
            ...DB::table('consumption_nutrition_snapshots')->whereIn('diary_consumption_state_id', $states)->pluck('audit_event_id')->all()];
        $subjects = [...$entries->map(fn ($id) => 'plan-entry:'.$id)->all(), ...$items->map(fn ($id) => 'plan-item-entry:'.$id)->all()];
        $audits = [...$audits, ...DB::table('audit_events')->where('subject_type', 'plan_snapshot')->whereIn('subject_identifier', $subjects)->pluck('id')->all()];
        foreach (PrivateConsumptionArchive::query()->where('user_id', $user->getKey())->cursor() as $archive) {
            foreach (['transitions', 'nutrition_snapshots'] as $collection) {
                foreach ($archive->history[$collection] as $row) {
                    $audits[] = $row['audit_event_id'];
                }
            }
        }
        $pending = DB::table('catalogue_correction_proposals')->where('proposer_user_id', $user->getKey())->where('state', 'pending')->pluck('id');
        $proposalSubjects = $pending->map(fn ($id) => 'proposal:'.$id)->all();
        $audits = [...$audits, ...DB::table('audit_events')->where('subject_type', 'catalogue_proposal')->whereIn('subject_identifier', $proposalSubjects)->pluck('id')->all()];
        foreach (array_unique($audits) as $id) {
            $resources[] = 'audit_'.$id;
        }

        return array_values(array_unique($resources));
    }
}
