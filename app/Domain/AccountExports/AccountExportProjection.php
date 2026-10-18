<?php

namespace App\Domain\AccountExports;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

final class AccountExportProjection
{
    private array $aliases = [];

    private int $started = 0;

    public function __construct(private readonly ExportContract $contract) {}

    /** Each collection is independently owner-scoped; foreign live content is never expanded. */
    public function domains(int $userId): array
    {
        $this->aliases = [];
        $this->started = hrtime(true);
        $recipes = DB::table('recipes')->where('user_id', $userId)->select('id');
        $plans = DB::table('meal_plans')->where('user_id', $userId)->select('id');
        $days = DB::table('meal_plan_days')->whereIn('meal_plan_id', $plans)->select('id');
        $slots = DB::table('meal_plan_slots')->whereIn('meal_plan_day_id', $days)->select('id');
        $recipeEntries = DB::table('meal_plan_recipe_entries')->whereIn('meal_plan_slot_id', $slots)->select('id');
        $itemEntries = DB::table('meal_plan_item_entries')->whereIn('meal_plan_slot_id', $slots)->select('id');
        $diaryEntries = DB::table('diary_entries')->where('user_id', $userId)->select('id');
        $states = DB::table('diary_consumption_states')->where(fn ($q) => $q
            ->whereIn('meal_plan_recipe_entry_id', $recipeEntries)->orWhereIn('meal_plan_item_entry_id', $itemEntries)
            ->orWhereIn('diary_entry_id', $diaryEntries))->select('id');
        $profiles = DB::table('nutrition_target_profiles')->where('user_id', $userId)->select('id');

        return [
            'account' => fn () => $this->account($userId),
            'recipes' => fn () => $this->rows('recipes', 'recipe', 'user_id', $userId, fn ($row) => $this->recipe($row)),
            'organisation' => fn () => $this->organisation($userId, $recipes),
            'plans' => fn () => $this->rows('meal_plans', 'plan', 'user_id', $userId, fn ($row) => $this->plan($row)),
            'diary' => fn () => (object) [
                'entries' => $this->queryRows(DB::table('diary_entries')->whereIn('id', $diaryEntries), 'diary_entry', fn ($r) => $this->sourceRefs($r, $r->kind)),
                'states' => $this->queryRows(DB::table('diary_consumption_states')->whereIn('id', $states), 'state'),
                'transitions' => $this->queryRows(DB::table('diary_consumption_transitions')->whereIn('diary_consumption_state_id', $states)->orderBy('diary_consumption_state_id')->orderBy('sequence'), 'transition'),
                'nutrition_snapshots' => $this->queryRows(DB::table('consumption_nutrition_snapshots')->whereIn('diary_consumption_state_id', $states), 'consumption_snapshot', function ($r) {
                    $r = $this->sourceRefs($r, $r->item_kind);
                    $r->source_entry_id = match ($r->source_entry_type) {
                        'meal_plan_recipe_entry' => 'recipe-entry:', 'meal_plan_item_entry' => 'item-entry:', 'diary_entry' => 'diary-entry:',
                        default => throw new \RuntimeException('export_source_entry_invalid'),
                    }.$r->source_entry_id;

                    return $r;
                }),
            ],
            'targets' => fn () => (object) [
                'profiles' => $this->queryRows(DB::table('nutrition_target_profiles')->whereIn('id', $profiles), 'target_profile', function ($r) {
                    $r->is_default = $r->is_default === null ? null : (bool) $r->is_default;
                    $r->targets = $this->rows('nutrition_targets', 'target', 'nutrition_target_profile_id', $r->id);

                    return $r;
                }),
                'phases' => $this->queryRows(DB::table('meal_plan_target_phases')->whereIn('meal_plan_id', $plans), 'phase', function ($r) {
                    $r->values = $this->rows('meal_plan_target_phase_values', 'phase_value', 'meal_plan_target_phase_id', $r->id);

                    return $r;
                }),
            ],
            'imports' => fn () => $this->rows('recipe_imports', 'import', 'user_id', $userId, function ($r) {
                $r->requires_review = (bool) $r->requires_review;

                return $r;
            }),
            'proposals' => fn () => (object) [
                'corrections' => $this->rows('catalogue_correction_proposals', 'proposal', 'proposer_user_id', $userId, function ($r) {
                    $r->changes = $this->rows('catalogue_correction_changes', 'change', 'proposal_id', $r->id);

                    return $r;
                }),
                'legacy_ingredients' => $this->rows('ingredients', 'legacy', 'user_id', $userId),
                'catalogue_submissions' => $this->rows('catalogue_items', 'submission', 'submitted_by_user_id', $userId, function ($r) {
                    $v = DB::table('catalogue_item_versions')->where('catalogue_item_id', $r->id)->where('version_number', 1)->first();
                    if ($v) {
                        $v->observations = $this->rows('catalogue_nutrient_observations', 'observation', 'catalogue_item_version_id', $v->id, function ($observation) {
                            $observation->precision_reduced = (bool) $observation->precision_reduced;

                            return $observation;
                        });
                        $v->nutrient_values = $this->rows('catalogue_nutrient_values', 'stored_fact', 'catalogue_item_version_id', $v->id);
                    }
                    $r->submitted_version = $v ? $this->record('submitted_content', $v) : null;

                    return $r;
                }),
                'term_suggestions' => $this->rows('managed_recipe_term_suggestions', 'suggestion', 'suggested_by_user_id', $userId),
            ],
        ];
    }

    private function account(int $id): object
    {
        $row = DB::table('users')->where('id', $id)->firstOrFail();
        $row->id = 'self';
        $profile = DB::table('public_profiles')->where('user_id', $id)->first();
        if ($profile) {
            foreach (['profile_enabled', 'show_public_recipes', 'show_public_remixes'] as $field) {
                $profile->$field = (bool) $profile->$field;
            }
        }
        $row->public_profile = $profile ? $this->record('profile', $profile) : null;

        return $this->record('account', $row);
    }

    private function recipe(stdClass $row): stdClass
    {
        $row->content = $this->draftContent($row);
        $row->versions = $this->rows('recipe_versions', 'version', 'recipe_id', $row->id, function ($v) {
            $v->content = StoredJson::decode($v->snapshot);
            $v->override_history = $this->rows('recipe_nutrition_override_events', 'override', 'recipe_version_id', $v->id);
            $v->recalculation_history = $this->queryRows(DB::table('recipe_nutrition_recalculations')->where('recipe_version_id', $v->id)->where('state', 'completed'), 'recalculation');
            $override = DB::table('recipe_nutrition_override_events')->where('recipe_version_id', $v->id)->orderByDesc('occurred_at')->orderByDesc('id')->first();
            $imported = $v->content['imported_nutrition'] ?? null;
            $recalculation = DB::table('recipe_nutrition_recalculations')->where('recipe_version_id', $v->id)->where('state', 'completed')->orderByDesc('completed_at')->orderByDesc('id')->first();
            $estimate = $recalculation ? StoredJson::decode($recalculation->estimate) : ($v->content['nutrition_estimate'] ?? []);
            $v->effective_nutrition = match (true) {
                $override?->resulting_values !== null => ['source' => 'creator_override', 'values' => StoredJson::decode($override->resulting_values), 'provenance' => ['override_event_id' => $override->id]],
                is_array($imported['per_serving'] ?? null) && $imported['per_serving'] !== [] => ['source' => 'imported_source', 'values' => $imported['per_serving'], 'provenance' => $imported['provenance'] ?? null],
                default => ['source' => 'ingredient_estimate', 'values' => $estimate['per_serving'] ?? [], 'provenance' => null],
            };

            return $v;
        }, 'version_number');
        $revision = DB::table('recipe_draft_revisions')->where('recipe_id', $row->id)->first();
        $row->active_revision = $revision ? $this->record('revision', $revision) : null;
        $row->public_tags = DB::table('public_recipe_tags')->where('recipe_id', $row->id)->orderBy('id')->pluck('name')->all();
        $row->managed_terms = $this->queryRows(DB::table('managed_recipe_terms')->whereIn('id', DB::table('managed_recipe_term_recipes')->where('recipe_id', $row->id)->select('managed_recipe_term_id')), 'term');

        return $row;
    }

    private function draftContent(stdClass $recipe): object
    {
        $lines = DB::table('recipe_ingredient_lines')->where('recipe_id', $recipe->id)->orderBy('position')->get()->map(function ($r) {
            $match = DB::table('recipe_ingredient_line_matches')->where('recipe_ingredient_line_id', $r->id)->first();
            $r->catalogue_match = $match ? $this->record('match', $match) : null;
            $r->requires_review = (bool) $r->requires_review;
            foreach (['parser_warnings', 'uncertain_fields'] as $key) {
                $r->$key = $r->$key === null ? [] : json_decode($r->$key, true, flags: JSON_THROW_ON_ERROR);
            }

            return $this->record('line', $r);
        })->all();
        $sections = $this->rows('recipe_instruction_sections', 'section', 'recipe_id', $recipe->id, function ($r) {
            $r->key = 'section-'.$r->id;

            return $r;
        }, 'position');
        $steps = $this->rows('recipe_instruction_steps', 'step', 'recipe_id', $recipe->id, function ($r) {
            $r->section_key = $r->section_id === null ? null : 'section-'.$r->section_id;
            $r->requires_review = (bool) $r->requires_review;
            foreach (['parser_warnings', 'uncertain_fields'] as $key) {
                $r->$key = $r->$key === null ? [] : json_decode($r->$key, true, flags: JSON_THROW_ON_ERROR);
            }

            return $r;
        }, 'position');
        $import = DB::table('recipe_imports')->where('recipe_id', $recipe->id)->where('user_id', $recipe->user_id)->value('nutrition_source');

        return $this->contract->project('content', ['title' => $recipe->title, 'servings' => $recipe->servings, 'visibility' => $recipe->visibility, 'ingredients' => $lines, 'sections' => $sections, 'steps' => $steps, 'imported_nutrition' => $import === null ? null : StoredJson::decode($import)]);
    }

    private function plan(stdClass $r): stdClass
    {
        $r->days = $this->rows('meal_plan_days', 'day', 'meal_plan_id', $r->id, function ($day) {
            $day->slots = $this->rows('meal_plan_slots', 'slot', 'meal_plan_day_id', $day->id, function ($slot) {
                $slot->recipe_entries = $this->rows('meal_plan_recipe_entries', 'recipe_entry', 'meal_plan_slot_id', $slot->id);
                $slot->item_entries = $this->rows('meal_plan_item_entries', 'item_entry', 'meal_plan_slot_id', $slot->id);

                return $slot;
            }, 'position');

            return $day;
        });
        $r->outgoing_shares = $this->rows('meal_plan_shares', 'share', 'meal_plan_id', $r->id, function ($share) {
            $recipient = (string) $share->recipient_user_id;
            $this->aliases[$recipient] ??= 'recipient-'.(count($this->aliases) + 1);
            $share->recipient_alias = $this->aliases[$recipient];

            return $share;
        });

        return $r;
    }

    private function organisation(int $userId, Builder $ownedRecipes): object
    {
        $groups = function (string $table, string $pivotPrefix, string $foreignKey) use ($userId, $ownedRecipes) {
            return $this->rows($table, 'organisation_group', 'user_id', $userId, function ($r) use ($pivotPrefix, $foreignKey, $userId, $ownedRecipes) {
                $recipeMemberships = $this->queryRows(DB::table($pivotPrefix.'_recipes')->where($foreignKey, $r->id)->whereIn('recipe_id', $ownedRecipes), 'membership');
                $bookmarkMemberships = $this->queryRows(DB::table($pivotPrefix.'_bookmarks')->where($foreignKey, $r->id)->whereIn('bookmark_id', DB::table('bookmarks')->where('user_id', $userId)->select('id')), 'membership');
                $r->memberships = [...$recipeMemberships, ...$bookmarkMemberships];
                $r->id = $pivotPrefix.':'.$r->id;

                return $r;
            });
        };
        $bookmarks = function (string $table, string $type, string $column, string $sourceTable) use ($userId) {
            return $this->rows($table, 'bookmark', 'user_id', $userId, function ($r) use ($type, $column, $sourceTable, $userId) {
                $r->availability = $this->availability($sourceTable, $r->$column, $userId);
                $r->id = $type.':'.$r->id;

                return $r;
            });
        };

        return (object) [
            'collections' => $groups('recipe_collections', 'recipe_collection', 'recipe_collection_id'),
            'private_tags' => $groups('private_recipe_tags', 'private_recipe_tag', 'private_recipe_tag_id'),
            'recipe_bookmarks' => $bookmarks('bookmarks', 'bookmark', 'recipe_id', 'recipes'),
            'plan_bookmarks' => $bookmarks('meal_plan_bookmarks', 'plan-bookmark', 'meal_plan_id', 'meal_plans'),
            'lineage' => $this->queryRows(DB::table('recipe_remix_lineages')->whereIn('remix_recipe_id', $ownedRecipes), 'lineage', function ($r) use ($userId) {
                $r->availability = $this->availability('recipes', $r->source_recipe_id, $userId);

                return $r;
            }),
        ];
    }

    private function availability(string $table, mixed $id, int $userId): string
    {
        if ($table === 'recipes') {
            return Recipe::query()->visibleTo(User::query()->findOrFail($userId))->whereKey($id)->exists() ? 'available' : 'unavailable';
        }
        // Only predicates over foreign plan references; never load a foreign content graph.
        $slots = DB::table('meal_plan_slots')->whereIn('meal_plan_day_id', DB::table('meal_plan_days')->where('meal_plan_id', $id)->select('id'))->select('id');
        $privateRecipes = DB::table('meal_plan_recipe_entries')->whereIn('meal_plan_slot_id', $slots)
            ->where(fn ($q) => $q->whereNull('recipe_snapshot->visibility')->orWhere('recipe_snapshot->visibility', '!=', 'public'))->exists();
        $unsafeItems = DB::table('meal_plan_item_entries')->whereIn('meal_plan_slot_id', $slots)
            ->where(fn ($q) => $q->where('kind', '!=', 'catalogue')->orWhereNull('catalogue_snapshot')->orWhereRaw('JSON_LENGTH(catalogue_snapshot) = 0')->orWhereNotNull('one_off_wording')->orWhereNotNull('one_off_nutrition'))->exists();
        $public = ! $privateRecipes && ! $unsafeItems && DB::table('meal_plans')->where('id', $id)->whereIn('visibility', ['public', 'retained_unlisted'])->exists();
        $shared = DB::table('meal_plan_shares')->where('meal_plan_id', $id)->where('recipient_user_id', $userId)
            ->when($privateRecipes, fn ($q) => $q->whereNotNull('private_recipe_snapshots_acknowledged_at'))->exists();

        return $public || $shared ? 'available' : 'unavailable';
    }

    private function sourceRefs(stdClass $r, string $kind): stdClass
    {
        $prefix = $kind === 'recipe' ? 'recipe' : 'catalogue';
        $r->source_id = $r->source_id === null ? null : $prefix.':'.$r->source_id;
        $r->source_version_id = $r->source_version_id === null ? null : $prefix.'-version:'.$r->source_version_id;

        return $r;
    }

    private function rows(string $table, string $definition, string $key, mixed $id, ?callable $map = null, string $order = 'id'): array
    {
        return $this->queryRows(DB::table($table)->where($key, $id)->orderBy($order), $definition, $map);
    }

    private function queryRows(Builder $query, string $definition, ?callable $map = null): array
    {
        $result = [];
        foreach ($query->cursor() as $row) {
            if (memory_get_usage(true) > 160 * 1024 * 1024 || (hrtime(true) - $this->started) / 1e9 > 35) {
                throw new \RuntimeException('export_projection_resource_limit');
            }
            $result[] = $this->record($definition, $map ? $map($row) : $row);
        }

        return $result;
    }

    private function record(string $definition, stdClass $row): object
    {
        $fields = (array) $row;
        if ($definition === 'consumption_snapshot') {
            $fields['is_estimate'] = (bool) $fields['is_estimate'];
        }
        foreach (['snapshot', 'recipe_snapshot', 'nutrition_snapshot', 'catalogue_snapshot', 'catalogue_nutrition_snapshot', 'one_off_nutrition', 'source_snapshot', 'nutrition', 'targets', 'warnings', 'provenance', 'nutrition_source', 'estimate', 'prior_values', 'resulting_values', 'before_value', 'proposed_value', 'keywords', 'categories', 'nutriments'] as $key) {
            if (isset($fields[$key]) && is_string($fields[$key]) && in_array(substr($fields[$key], 0, 1), ['{', '['], true)) {
                $fields[$key] = StoredJson::decode($fields[$key]);
            }
        }

        return $this->contract->project($definition, $fields, databaseRow: true);
    }
}
