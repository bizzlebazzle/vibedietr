<?php

namespace App\Domain\MealPlans;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditSubjectType;
use App\Models\MealPlan;
use App\Models\MealPlanDay;
use App\Models\MealPlanItemEntry;
use App\Models\MealPlanRecipeEntry;
use App\Models\MealPlanSlot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class MealPlanCopier
{
    public function __construct(private readonly AuditEventRecorder $audit) {}

    public function copy(int $sourceId, User $actor): MealPlan
    {
        return DB::transaction(function () use ($sourceId, $actor): MealPlan {
            $source = MealPlan::query()->lockForUpdate()->findOrFail($sourceId);
            Gate::forUser($actor)->authorize('copy', $source);
            $source->load([
                'days.slots.recipeEntries',
                'days.slots.itemEntries',
            ]);

            $copy = new MealPlan;
            $copy->forceFill([
                'user_id' => $actor->getKey(),
                'name' => $source->name,
                'type' => $source->type,
                'visibility' => MealPlanVisibility::Private,
                'starts_on' => $source->starts_on,
                'ends_on' => $source->ends_on,
                'published_at' => null,
                'retained_unlisted_at' => null,
            ])->save();

            foreach ($source->days as $sourceDay) {
                $day = new MealPlanDay;
                $day->forceFill([
                    'day_index' => $sourceDay->day_index,
                    'date' => $sourceDay->date,
                ]);
                $day->mealPlan()->associate($copy);
                $day->save();

                foreach ($sourceDay->slots as $sourceSlot) {
                    $slot = new MealPlanSlot;
                    $slot->forceFill([
                        'standard_key' => $sourceSlot->standard_key,
                        'name' => $sourceSlot->name,
                        'position' => $sourceSlot->position,
                    ]);
                    $slot->day()->associate($day);
                    $slot->save();

                    foreach ($sourceSlot->recipeEntries as $sourceEntry) {
                        $entry = new MealPlanRecipeEntry;
                        $entry->forceFill([
                            'recipe_id' => $sourceEntry->recipe_id,
                            'recipe_version_id' => $sourceEntry->recipe_version_id,
                            'recipe_version_number' => $sourceEntry->recipe_version_number,
                            'planned_servings' => $sourceEntry->planned_servings,
                            'recipe_snapshot' => $sourceEntry->recipe_snapshot,
                            'nutrition_snapshot' => $sourceEntry->nutrition_snapshot,
                        ]);
                        $entry->slot()->associate($slot);
                        $entry->save();
                    }

                    foreach ($sourceSlot->itemEntries as $sourceEntry) {
                        $entry = new MealPlanItemEntry;
                        $entry->forceFill([
                            'kind' => $sourceEntry->kind,
                            'planned_amount' => $sourceEntry->planned_amount,
                            'planned_unit' => $sourceEntry->planned_unit,
                            'catalogue_item_id' => $sourceEntry->catalogue_item_id,
                            'catalogue_item_version_id' => $sourceEntry->catalogue_item_version_id,
                            'catalogue_item_version_number' => $sourceEntry->catalogue_item_version_number,
                            'catalogue_snapshot' => $sourceEntry->catalogue_snapshot,
                            'catalogue_nutrition_snapshot' => $sourceEntry->catalogue_nutrition_snapshot,
                            'one_off_wording' => $sourceEntry->one_off_wording,
                            'one_off_nutrition' => $sourceEntry->one_off_nutrition,
                        ]);
                        $entry->slot()->associate($slot);
                        $entry->save();
                    }
                }
            }

            $this->audit->record(
                AuditAction::PlanCopied,
                AuditActor::authenticatedUser($actor),
                AuditSubject::resource(AuditSubjectType::MealPlan, $copy->getKey()),
                ['outcome' => 'completed', 'source_visibility' => $source->visibility->value],
                'plan-copy:'.Str::ulid(),
            );

            return $copy;
        }, 3);
    }
}
