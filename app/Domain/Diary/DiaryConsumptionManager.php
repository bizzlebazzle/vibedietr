<?php

namespace App\Domain\Diary;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditSubjectType;
use App\Domain\MealPlans\MealPlanRecipeVersionReviewNotifier;
use App\Models\DiaryConsumptionState;
use App\Models\DiaryConsumptionTransition;
use App\Models\DiaryEntry;
use App\Models\MealPlanItemEntry;
use App\Models\MealPlanRecipeEntry;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DiaryConsumptionManager
{
    public function __construct(
        private readonly ConsumptionTimeResolver $times,
        private readonly ConsumptionNutritionSnapshotter $snapshots,
        private readonly AuditEventRecorder $audit,
        private readonly MealPlanRecipeVersionReviewNotifier $recipeVersionReviews,
    ) {}

    public function consumeRecipe(MealPlanRecipeEntry $entry, ?string $amount, ?string $local, ?string $timezone, ?int $offset, User $actor): DiaryConsumptionTransition
    {
        return $this->consume($entry, 'meal_plan_recipe_entry_id', $amount, 'serving', $local, $timezone, $offset, null, $actor);
    }

    public function consumeItem(MealPlanItemEntry $entry, ?string $amount, ?string $local, ?string $timezone, ?int $offset, User $actor): DiaryConsumptionTransition
    {
        return $this->consume($entry, 'meal_plan_item_entry_id', $amount, $entry->planned_unit->value, $local, $timezone, $offset, null, $actor);
    }

    public function consumeDiary(DiaryEntry $entry, string $amount, string $unit, ?string $local, ?string $timezone, ?int $offset, ?string $diaryDate, User $actor): DiaryConsumptionTransition
    {
        return $this->consume($entry, 'diary_entry_id', $amount, $unit, $local, $timezone, $offset, $diaryDate, $actor);
    }

    public function correct(MealPlanRecipeEntry|MealPlanItemEntry|DiaryEntry $entry, string $foreignKey, ?string $amount, ?string $local, ?string $timezone, ?int $offset, ?string $diaryDate, User $actor): DiaryConsumptionTransition
    {
        return DB::transaction(function () use ($entry, $foreignKey, $amount, $local, $timezone, $offset, $diaryDate, $actor) {
            [$locked, $state, $current] = $this->lockedCurrent($entry, $foreignKey, $actor);
            if (! $current || $current->action === ConsumptionAction::Reverse) {
                throw ValidationException::withMessages(['consumption' => 'Only an active consumption can be corrected.']);
            }
            $resolved = $this->resolvedForCorrection($current, $local, $timezone, $offset);
            $effectiveDate = $this->effectiveDate($locked, $resolved, $diaryDate);

            $correctedAmount = $amount ?? $current->actual_amount;
            $snapshotId = $current->consumption_snapshot_id;
            if (! BigDecimal::of($correctedAmount)->isEqualTo(BigDecimal::of($current->actual_amount))) {
                $snapshotId = $this->snapshots->create($locked, $state, $correctedAmount, $current->actual_unit)->getKey();
            }

            return $this->append($state, ConsumptionAction::Correct, $actor, $correctedAmount, $current->actual_unit, $resolved, $effectiveDate, $snapshotId, $current);
        }, 3);
    }

    public function reverse(MealPlanRecipeEntry|MealPlanItemEntry|DiaryEntry $entry, string $foreignKey, User $actor): DiaryConsumptionTransition
    {
        return DB::transaction(function () use ($entry, $foreignKey, $actor) {
            [, $state, $current] = $this->lockedCurrent($entry, $foreignKey, $actor);
            if (! $current || $current->action === ConsumptionAction::Reverse) {
                throw ValidationException::withMessages(['consumption' => 'Only an active consumption can be reversed.']);
            }
            $transition = $this->append($state, ConsumptionAction::Reverse, $actor, null, null, null, null, null, $current);
            $state->forceFill(['current_transition_id' => null])->save();

            return $transition;
        }, 3);
    }

    private function consume(MealPlanRecipeEntry|MealPlanItemEntry|DiaryEntry $entry, string $foreignKey, ?string $amount, string $unit, ?string $local, ?string $timezone, ?int $offset, ?string $diaryDate, User $actor): DiaryConsumptionTransition
    {
        return DB::transaction(function () use ($entry, $foreignKey, $amount, $unit, $local, $timezone, $offset, $diaryDate, $actor) {
            $locked = $this->reload($entry);
            $this->authorize($locked, $actor);
            $state = DiaryConsumptionState::query()->where($foreignKey, $locked->getKey())->lockForUpdate()->first();
            if (! $state) {
                $state = new DiaryConsumptionState;
                $state->forceFill([$foreignKey => $locked->getKey(), 'next_sequence' => 1]);
                $state->save();
            }
            if ($state->current_transition_id !== null) {
                throw ValidationException::withMessages(['consumption' => 'This entry is already consumed.']);
            }
            $hasHistory = $state->next_sequence > 1;
            $action = $hasHistory ? ConsumptionAction::Reconsume : ConsumptionAction::Consume;
            if ($amount === null) {
                if ($action !== ConsumptionAction::Consume || $locked instanceof DiaryEntry) {
                    throw ValidationException::withMessages(['actual_amount' => 'Enter the actual amount.']);
                }
                $amount = $locked instanceof MealPlanRecipeEntry ? $locked->planned_servings : $locked->planned_amount;
            }
            $zone = $timezone ?: $actor->timezone;
            $plannedDate = $this->plannedDate($locked);
            $today = Date::now()->setTimezone($zone)->toDateString();
            $resolved = $this->times->resolve($local, $zone, $offset, $locked instanceof DiaryEntry || $plannedDate === $today);
            $effectiveDate = $this->effectiveDate($locked, $resolved, $diaryDate);
            $predecessor = $state->next_sequence > 1 ? $state->transitions()->reorder()->orderByDesc('sequence')->first() : null;

            $snapshot = $this->snapshots->create($locked, $state, $amount, $unit);
            if ($locked instanceof MealPlanRecipeEntry) {
                $this->recipeVersionReviews->resolvePendingForConsumedEntry($locked->getKey());
            }

            return $this->append($state, $action, $actor, $amount, $unit, $resolved, $effectiveDate, $snapshot->getKey(), $predecessor);
        }, 3);
    }

    /** @return array{MealPlanRecipeEntry|MealPlanItemEntry|DiaryEntry, DiaryConsumptionState, ?DiaryConsumptionTransition} */
    private function lockedCurrent(MealPlanRecipeEntry|MealPlanItemEntry|DiaryEntry $entry, string $foreignKey, User $actor): array
    {
        $locked = $this->reload($entry);
        $this->authorize($locked, $actor);
        $state = DiaryConsumptionState::query()->where($foreignKey, $locked->getKey())->lockForUpdate()->first();
        $current = $state?->current_transition_id ? DiaryConsumptionTransition::query()->find($state->current_transition_id) : null;
        if (! $state) {
            throw ValidationException::withMessages(['consumption' => 'This entry has no consumption history.']);
        }

        return [$locked, $state, $current];
    }

    private function append(DiaryConsumptionState $state, ConsumptionAction $action, User $actor, ?string $amount, ?string $unit, ?ResolvedConsumptionTime $time, ?string $effectiveDate, ?string $snapshotId, ?DiaryConsumptionTransition $predecessor): DiaryConsumptionTransition
    {
        if ($amount !== null && BigDecimal::of($amount)->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages(['actual_amount' => 'The actual amount must be greater than zero.']);
        }
        $id = (string) Str::ulid();
        $audit = $this->audit->record(AuditAction::DiaryConsumptionTransitioned, AuditActor::authenticatedUser($actor), AuditSubject::resource(AuditSubjectType::DiaryConsumptionTransition, $id), ['transition_kind' => $action->value, 'outcome' => 'completed'], 'diary-consumption:'.Str::ulid());
        $transition = new DiaryConsumptionTransition;
        $transition->forceFill([
            'id' => $id, 'diary_consumption_state_id' => $state->getKey(), 'sequence' => $state->next_sequence,
            'action' => $action, 'predecessor_id' => $predecessor?->getKey(),
            'target_transition_id' => $action === ConsumptionAction::Reverse ? $predecessor?->getKey() : null,
            'actor_user_id' => $actor->getKey(), 'recorded_at' => Date::now()->utc(),
            'actual_amount' => $amount, 'actual_unit' => $unit,
            'consumed_local_at' => $time?->local->format('Y-m-d H:i:s'), 'timezone' => $time?->timezone,
            'utc_offset_minutes' => $time?->offsetMinutes, 'consumed_at_utc' => $time?->utc,
            'effective_diary_date' => $effectiveDate, 'consumption_snapshot_id' => $snapshotId,
            'audit_event_id' => $audit->getKey(),
        ])->save();
        $state->forceFill(['current_transition_id' => $action === ConsumptionAction::Reverse ? null : $id, 'next_sequence' => $state->next_sequence + 1])->save();

        return $transition;
    }

    private function reload(MealPlanRecipeEntry|MealPlanItemEntry|DiaryEntry $entry): MealPlanRecipeEntry|MealPlanItemEntry|DiaryEntry
    {
        return match (true) {
            $entry instanceof MealPlanRecipeEntry => MealPlanRecipeEntry::query()->lockForUpdate()->findOrFail($entry->getKey()),
            $entry instanceof MealPlanItemEntry => MealPlanItemEntry::query()->lockForUpdate()->findOrFail($entry->getKey()),
            default => DiaryEntry::query()->lockForUpdate()->findOrFail($entry->getKey()),
        };
    }

    private function authorize(MealPlanRecipeEntry|MealPlanItemEntry|DiaryEntry $entry, User $actor): void
    {
        $ownerId = $entry instanceof DiaryEntry ? $entry->user_id : $entry->slot()->firstOrFail()->day()->firstOrFail()->mealPlan()->value('user_id');
        if ((int) $ownerId !== (int) $actor->getKey()) {
            abort(403);
        }
        if (! $entry instanceof DiaryEntry && $entry->slot()->firstOrFail()->day()->value('date') === null) {
            throw ValidationException::withMessages(['consumption' => 'Reusable undated plan entries cannot be consumed.']);
        }
    }

    private function plannedDate(MealPlanRecipeEntry|MealPlanItemEntry|DiaryEntry $entry): ?string
    {
        return $entry instanceof DiaryEntry ? null : $entry->slot()->firstOrFail()->day()->firstOrFail()->date?->toDateString();
    }

    private function effectiveDate(MealPlanRecipeEntry|MealPlanItemEntry|DiaryEntry $entry, ResolvedConsumptionTime $time, ?string $requested): string
    {
        $localDate = $time->local->toDateString();
        if (! $entry instanceof DiaryEntry) {
            $planned = $this->plannedDate($entry);
            $next = CarbonImmutable::parse($planned)->addDay()->toDateString();
            if (! in_array($localDate, [$planned, $next], true)) {
                throw ValidationException::withMessages(['consumed_local_at' => 'A dated plan entry can be consumed only on its planned date or the following local date.']);
            }

            return $planned;
        }
        $effective = $requested ?: $localDate;
        if (! in_array($effective, [$localDate, CarbonImmutable::parse($localDate)->subDay()->toDateString()], true)) {
            throw ValidationException::withMessages(['effective_diary_date' => 'The diary date must be the local consumption date or the immediately preceding date.']);
        }

        return $effective;
    }

    private function resolvedForCorrection(DiaryConsumptionTransition $current, ?string $local, ?string $timezone, ?int $offset): ResolvedConsumptionTime
    {
        if ($local === null && $timezone === null && $offset === null) {
            return new ResolvedConsumptionTime($current->consumed_at_utc->setTimezone($current->timezone), $current->timezone, $current->utc_offset_minutes, $current->consumed_at_utc);
        }

        return $this->times->resolve($local ?? $current->consumed_local_at->format('Y-m-d H:i:s'), $timezone ?? $current->timezone, $offset, false);
    }
}
