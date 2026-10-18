<?php

namespace App\Domain\AccountDeletion;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Domain\AccountExports\CleanupAccountExport;
use App\Domain\MealPlans\MealPlanBookmarkManager;
use App\Domain\MealPlans\MealPlanPublicSafety;
use App\Domain\Nutrition\RecipeNutritionSourceSelector;
use App\Domain\RecipeImports\RecipeImportOwnerCleaner;
use App\Domain\Recipes\RecipeVersionContent;
use App\Models\AccountDeletion;
use App\Models\AccountExport;
use App\Models\MealPlan;
use App\Models\MealPlanBookmark;
use App\Models\PrivateConsumptionArchive;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class PurgeAccount
{
    public function purge(string $id): void
    {
        app(AccountLifecycle::class)->apply($id);
        $record = AccountDeletion::query()->find($id);
        if (! $record || $record->deadline->isFuture()) {
            return;
        }
        DB::transaction(function () use ($id): void {
            $record = AccountDeletion::query()->lockForUpdate()->find($id);
            if (! $record || $record->deadline->isFuture()) {
                return;
            }
            if ($record->state !== 'purging') {
                $record->forceFill(['state' => 'purging', 'operation_id' => (string) Str::ulid()])->save();
            }
        });
        $record->refresh();
        $intent = app(AccountLifecycle::class)->intent($record, 'purge_started');
        app(JournalManifest::class)->append($record->operation_id, $intent);
        $this->erase($intent, $intent['resources']);
        $intent['state'] = 'purged';
        app(JournalManifest::class)->append($id, $intent);
    }

    /** Reapply even completed purges after restore; no restored completion flag bypass.
     * @param  array<string, mixed>  $intent
     * @param  list<string>  $resources  Expanded, verified journal manifest.
     */
    public function erase(array $intent, array $resources): void
    {
        $user = User::query()->where('account_generation', $intent['account_generation'])->first();
        if ($user) {
            app(RecipeImportOwnerCleaner::class)->cancelAndCleanup((int) $user->getKey());
        }
        $exports = AccountExport::query()->where(function ($query) use ($user, $resources): void {
            $query->whereIn('id', $this->generations($resources, 'export_'));
            if ($user) {
                $query->orWhere('user_id', $user->getKey());
            }
        })->get();
        foreach ($exports as $export) {
            if ($export->state !== 'deleted') {
                $export->forceFill(['state' => 'expired', 'active_key' => null])->save();
                app(CleanupAccountExport::class)->cleanup($export->getKey());
            }
            if ($export->fresh()?->state !== 'deleted') {
                throw new RuntimeException('account_export_purge_incomplete');
            }
        }
        DB::transaction(function () use ($intent, $resources): void {
            $user = User::query()->where('account_generation', $intent['account_generation'])->lockForUpdate()->first();
            $under13 = in_array('policy_under13_no_recovery', $resources, true)
                || ($user && AccountDeletion::query()->where('user_id', $user->getKey())->where('reason', 'under13')->exists());
            $planGenerations = $this->generations($resources, 'plan_');
            $recipeGenerations = $this->generations($resources, 'recipe_');
            $privateEvents = $this->generations($resources, 'audit_');
            if ($user) {
                foreach (PrivateConsumptionArchive::query()->where('user_id', $user->getKey())->get() as $archive) {
                    foreach (['transitions', 'nutrition_snapshots'] as $collection) {
                        foreach ($archive->history[$collection] as $row) {
                            $privateEvents[] = $row['audit_event_id'];
                        }
                    }
                }
            }
            if ($user) {
                // Deleting this user's bookmarks must also clean another source's
                // retained plan when it loses its final qualifying bookmark.
                foreach (MealPlanBookmark::query()->where('user_id', $user->getKey())->get() as $bookmark) {
                    app(MealPlanBookmarkManager::class)->remove($bookmark, $user);
                }
            }
            foreach (MealPlan::query()->whereIn('erasure_generation', $planGenerations)->lockForUpdate()->get() as $plan) {
                if ($plan->user_id !== null && (! $user || (int) $plan->user_id !== (int) $user->getKey())) {
                    throw new RuntimeException('erasure_resource_ownership_mismatch');
                }
                $privateEvents = [...$privateEvents, ...$this->clearPlanPrivateState($plan)];
                $retained = ! $under13 && $plan->visibility->value === 'retained_unlisted'
                    && $plan->bookmarks()->exists() && app(MealPlanPublicSafety::class)->isPublicSafe($plan)
                    && ! DB::table('retained_plan_removals')->where('resource_generation', $plan->erasure_generation)->exists();
                if (! $retained) {
                    DB::table('meal_plans')->where('id', $plan->getKey())->delete();

                    continue;
                }
                $this->minimizePlan($plan);
            }
            $publicRecipeIds = [];
            foreach (Recipe::query()->whereIn('erasure_generation', $recipeGenerations)->lockForUpdate()->get() as $recipe) {
                if ($recipe->user_id !== null && (! $user || (int) $recipe->user_id !== (int) $user->getKey())) {
                    throw new RuntimeException('erasure_resource_ownership_mismatch');
                }
                if ($under13 || ! $recipe->isPubliclyViewable()) {
                    DB::table('recipes')->where('id', $recipe->getKey())->delete();

                    continue;
                }
                $this->minimizeRecipe($recipe);
                $publicRecipeIds[] = (int) $recipe->getKey();
            }
            $this->queuedResources($resources);
            DB::table('audit_events')->whereIn('id', $privateEvents)
                ->where('retention_class', 'private_content_until_final_purge')->delete();
            if (! $user) {
                app(AuditErasure::class)->erase(null, $publicRecipeIds, $privateEvents, $this->generations($resources, 'identity_'));

                return;
            }
            $this->catalogue($user);
            $diaryStates = DB::table('diary_consumption_states')->whereIn('diary_entry_id',
                DB::table('diary_entries')->where('user_id', $user->getKey())->select('id'))->pluck('id');
            $privateEvents = [...$privateEvents, ...$this->deleteConsumption($diaryStates->all())];
            app(AuditErasure::class)->erase($user, $publicRecipeIds, $privateEvents, $this->generations($resources, 'identity_'));
            DB::table('administrator_promotion_requests')->where('target_user_id', $user->getKey())->delete();
            DB::table('account_exports')->where('user_id', $user->getKey())->delete();
            DB::table('security_notification_intents')->where('recipient_user_id', $user->getKey())->delete();
            DB::table('administrator_promotion_requests')->where('initiated_by_user_id', $user->getKey())->where('status', 'pending')->update(['status' => 'cancelled', 'cancelled_at' => now()->utc()]);
            DB::table('sessions')->where('user_id', $user->getKey())->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            DB::table('account_deletions')->where('user_id', $user->getKey())->delete();
            DB::table('users')->where('id', $user->getKey())->delete();
            DB::table('account_erasure_receipts')->insert(['id' => (string) Str::ulid(), 'completed_at' => now()->utc()]);
            app(AuditEventRecorder::class)->record(
                AuditAction::AccountAnonymizationCompleted, AuditActor::system(), AuditSubject::system(),
                ['anonymization_scope' => 'account_final_purge', 'outcome' => 'completed'],
            );
        }, 3);
    }

    /** @return list<string> */
    private function clearPlanPrivateState(MealPlan $plan): array
    {
        $slots = DB::table('meal_plan_slots')->whereIn('meal_plan_day_id',
            DB::table('meal_plan_days')->where('meal_plan_id', $plan->getKey())->select('id'))->select('id');
        $entries = DB::table('meal_plan_recipe_entries')->whereIn('meal_plan_slot_id', $slots)->pluck('id');
        $items = DB::table('meal_plan_item_entries')->whereIn('meal_plan_slot_id', $slots)->pluck('id');
        $states = DB::table('diary_consumption_states')->whereIn('meal_plan_recipe_entry_id', $entries)
            ->orWhereIn('meal_plan_item_entry_id', $items)->pluck('id');
        $events = $this->deleteConsumption($states->all());
        DB::table('meal_plan_recipe_version_reviews')->whereIn('meal_plan_recipe_entry_id', $entries)->delete();
        DB::table('meal_plan_target_phases')->where('meal_plan_id', $plan->getKey())->delete();
        DB::table('meal_plan_shares')->where('meal_plan_id', $plan->getKey())->delete();

        return $events;
    }

    /** @param list<int> $states
     * @return list<string>
     */
    private function deleteConsumption(array $states): array
    {
        $events = DB::table('diary_consumption_transitions')->whereIn('diary_consumption_state_id', $states)->pluck('audit_event_id')->all();
        $events = [...$events, ...DB::table('consumption_nutrition_snapshots')->whereIn('diary_consumption_state_id', $states)->pluck('audit_event_id')->all()];
        DB::table('diary_consumption_transitions')->whereIn('diary_consumption_state_id', $states)->delete();
        DB::table('diary_consumption_states')->whereIn('id', $states)->delete();

        return $events;
    }

    private function minimizePlan(MealPlan $plan): void
    {
        $plan->load(['days.slots.recipeEntries', 'days.slots.itemEntries']);
        foreach ($plan->days as $day) {
            foreach ($day->slots as $slot) {
                foreach ($slot->recipeEntries as $entry) {
                    DB::table('meal_plan_recipe_entries')->where('id', $entry->getKey())->update([
                        'recipe_snapshot' => json_encode(app(PublicContentMinimizer::class)->recipe($entry->recipe_snapshot), JSON_THROW_ON_ERROR),
                        'nutrition_snapshot' => json_encode(app(PublicContentMinimizer::class)->nutrition($entry->nutrition_snapshot), JSON_THROW_ON_ERROR),
                    ]);
                }
                foreach ($slot->itemEntries as $entry) {
                    DB::table('meal_plan_item_entries')->where('id', $entry->getKey())->update([
                        'catalogue_snapshot' => json_encode(app(PublicContentMinimizer::class)->catalogue($entry->catalogue_snapshot), JSON_THROW_ON_ERROR),
                    ]);
                }
            }
        }
    }

    private function minimizeRecipe(Recipe $recipe): void
    {
        $current = $recipe->currentVersion()->firstOrFail();
        $selector = app(RecipeNutritionSourceSelector::class);
        $effective = $selector->effective($current);
        $publicNutrition = app(PublicContentMinimizer::class)->nutrition([
            'source' => $effective['source']->value, 'values' => $effective['values'],
            'provenance' => $effective['provenance'], 'ingredient_estimate' => $selector->estimate($current),
        ]);
        $currentSnapshot = $current->snapshot;
        // Current recipe visibility authorizes its current version. Old pinned
        // plan snapshots keep their own original visibility and are never upgraded.
        $currentSnapshot['visibility'] = 'public';
        $currentSnapshot['nutrition_estimate'] = $selector->estimate($current);
        $snapshot = app(PublicContentMinimizer::class)->recipe($currentSnapshot);
        // Discard mutable active-revision content, then reconstruct only the
        // already-public immutable current version before severing ownership.
        DB::table('recipe_draft_revisions')->where('recipe_id', $recipe->getKey())->delete();
        app(RecipeVersionContent::class)->restore($recipe, $snapshot);
        foreach ($recipe->versions()->orderBy('version_number')->get() as $version) {
            if ($version->getKey() !== $current->getKey()) {
                app(AuditProvenanceClock::class)->withdraw($version);
                $required = DB::table('recipe_remix_lineages')->where('source_recipe_version_id', $version->getKey())
                    ->whereIn('remix_recipe_id', DB::table('recipes')->where('user_id', '!=', $recipe->user_id)->select('id'))->exists();
                if (! $required) {
                    DB::table('recipe_versions')->where('id', $version->getKey())->delete();

                    continue;
                }
                $content = ['title' => (string) ($version->snapshot['title'] ?? ''), 'visibility' => 'public'];
                DB::table('recipe_nutrition_override_events')->where('recipe_version_id', $version->getKey())->delete();
                DB::table('recipe_nutrition_recalculations')->where('recipe_version_id', $version->getKey())->delete();
            } else {
                $content = $snapshot;
            }
            DB::table('recipe_nutrition_override_events')->where('recipe_version_id', $version->getKey())->delete();
            DB::table('recipe_nutrition_recalculations')->where('recipe_version_id', $version->getKey())->delete();
            DB::table('recipe_versions')->where('id', $version->getKey())->update([
                'retained_public_nutrition' => $version->getKey() === $current->getKey() ? json_encode($publicNutrition, JSON_THROW_ON_ERROR) : null,
                'snapshot' => json_encode($content, JSON_THROW_ON_ERROR),
                'public_attribution_name' => 'Former VibeDietr user',
            ]);
        }
        DB::table('recipes')->where('id', $recipe->getKey())->update(['user_id' => null]);
    }

    private function catalogue(User $user): void
    {
        $pending = DB::table('catalogue_correction_proposals')->where('proposer_user_id', $user->getKey())->where('state', 'pending')->pluck('id');
        DB::table('catalogue_correction_changes')->whereIn('proposal_id', $pending)->delete();
        DB::table('catalogue_correction_proposals')->whereIn('id', $pending)->delete();
        $rejected = DB::table('catalogue_correction_proposals')->where('proposer_user_id', $user->getKey())->where('state', 'rejected')->pluck('id');
        DB::table('catalogue_correction_changes')->whereIn('proposal_id', $rejected)->delete();
        DB::table('catalogue_correction_proposals')->where('proposer_user_id', $user->getKey())->update(['proposer_user_id' => null, 'reason' => '']);
        DB::table('catalogue_duplicate_candidates')->where('submitted_by_user_id', $user->getKey())->update([
            'submitted_by_user_id' => null, 'distinction_explanation' => null,
        ]);
        DB::table('managed_recipe_term_suggestions')->where('suggested_by_user_id', $user->getKey())->where('status', 'pending')->delete();
        foreach (DB::table('legacy_ingredient_catalogue_mappings')->where('legacy_user_id', $user->getKey())->get() as $mapping) {
            $snapshot = json_decode($mapping->legacy_snapshot, true, flags: JSON_THROW_ON_ERROR);
            unset($snapshot['user_id']);
            DB::table('legacy_ingredient_catalogue_mappings')->where('id', $mapping->id)->update([
                'legacy_user_id' => null, 'legacy_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'legacy_checksum' => hash('sha256', json_encode(['id' => $mapping->ingredient_id, 'user_id' => null] + $snapshot, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)),
            ]);
        }
    }

    /** @param list<string> $resources */
    private function queuedResources(array $resources): void
    {
        $ids = [...$this->generations($resources, 'import_'), ...$this->generations($resources, 'export_'), ...$this->generations($resources, 'recalculation_')];
        foreach (array_chunk($ids, 100) as $chunk) {
            foreach (['jobs', 'failed_jobs'] as $table) {
                DB::table($table)->where(function ($query) use ($chunk): void {
                    foreach ($chunk as $id) {
                        // Known globally unique workflow IDs in metadata-only job payloads.
                        $query->orWhere('payload', 'like', '%'.$id.'%');
                    }
                })->delete();
            }
        }
    }

    /** @param list<string> $resources
     * @return list<string>
     */
    private function generations(array $resources, string $prefix): array
    {
        return array_values(array_map(fn (string $value): string => substr($value, strlen($prefix)),
            array_filter($resources, fn (string $value): bool => str_starts_with($value, $prefix))));
    }
}
