<?php

namespace App\Domain\MealPlans;

use App\Models\MealPlan;
use App\Models\MealPlanDay;
use App\Models\MealPlanSlot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class MealPlanDayWriter
{
    public function createDay(MealPlan $mealPlan, ?int $dayIndex, ?CarbonImmutable $date): MealPlanDay
    {
        Gate::authorize('update', $mealPlan);

        return DB::transaction(function () use ($mealPlan, $dayIndex, $date): MealPlanDay {
            $mealPlan = MealPlan::query()->lockForUpdate()->findOrFail($mealPlan->getKey());
            $this->validateDayIdentity($mealPlan, $dayIndex, $date);

            $duplicate = $mealPlan->days()
                ->when(
                    $mealPlan->type === MealPlanType::Reusable,
                    fn ($query) => $query->where('day_index', $dayIndex),
                    fn ($query) => $query->whereDate('date', $date?->toDateString())
                )
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    $mealPlan->type === MealPlanType::Reusable ? 'day_index' : 'date' => 'That day already exists in this meal plan.',
                ]);
            }

            $day = new MealPlanDay;
            $day->day_index = $dayIndex;
            $day->date = $date;
            $day->mealPlan()->associate($mealPlan);
            $day->save();

            foreach (PlanSlotKey::cases() as $position => $key) {
                $slot = new MealPlanSlot;
                $slot->standard_key = $key;
                $slot->name = $key->defaultName();
                $slot->position = $position;
                $slot->day()->associate($day);
                $slot->save();
            }

            return $day->load('slots');
        });
    }

    public function renameSlot(MealPlanDay $day, int $slotId, string $name): MealPlanSlot
    {
        Gate::authorize('update', $day->mealPlan);

        return DB::transaction(function () use ($day, $slotId, $name): MealPlanSlot {
            $day = MealPlanDay::query()->lockForUpdate()->findOrFail($day->getKey());
            $slot = $day->slots()->lockForUpdate()->findOrFail($slotId);

            if ($slot->standard_key?->hasFixedName()) {
                throw ValidationException::withMessages([
                    'name' => $slot->standard_key->defaultName().' is a fixed-name slot.',
                ]);
            }

            $slot->update(['name' => $name]);

            return $slot;
        });
    }

    public function addSlot(MealPlanDay $day, string $name): MealPlanSlot
    {
        Gate::authorize('update', $day->mealPlan);

        return DB::transaction(function () use ($day, $name): MealPlanSlot {
            $day = MealPlanDay::query()->lockForUpdate()->findOrFail($day->getKey());
            $lastPosition = $day->slots()->max('position');
            $slot = new MealPlanSlot(['name' => $name]);
            $slot->standard_key = null;
            $slot->position = $lastPosition === null ? 0 : ((int) $lastPosition) + 1;
            $slot->day()->associate($day);
            $slot->save();

            return $slot;
        });
    }

    /** @param list<int> $slotIds */
    public function reorderSlots(MealPlanDay $day, array $slotIds): void
    {
        Gate::authorize('update', $day->mealPlan);

        DB::transaction(function () use ($day, $slotIds): void {
            $day = MealPlanDay::query()->lockForUpdate()->findOrFail($day->getKey());
            $slots = $day->slots()->lockForUpdate()->get()->keyBy('id');
            $expectedIds = $slots->keys()->map(static fn ($id): int => (int) $id)->all();

            if (count($slotIds) !== count(array_unique($slotIds, SORT_REGULAR))) {
                throw ValidationException::withMessages(['slot_ids' => 'Each slot may appear only once.']);
            }

            $submittedIds = $slotIds;
            sort($submittedIds);
            sort($expectedIds);

            if ($submittedIds !== $expectedIds) {
                throw ValidationException::withMessages([
                    'slot_ids' => 'The order must contain every slot from this plan day and no others.',
                ]);
            }

            $temporaryBase = ((int) $slots->max('position')) + $slots->count() + 1;

            foreach ($slotIds as $position => $slotId) {
                $slot = $slots->get($slotId);
                assert($slot instanceof MealPlanSlot);
                $slot->position = $temporaryBase + $position;
                $slot->save();
            }

            foreach ($slotIds as $position => $slotId) {
                $slot = $slots->get($slotId);
                assert($slot instanceof MealPlanSlot);
                $slot->position = $position;
                $slot->save();
            }
        });
    }

    private function validateDayIdentity(MealPlan $mealPlan, ?int $dayIndex, ?CarbonImmutable $date): void
    {
        if ($mealPlan->type === MealPlanType::Reusable) {
            if ($dayIndex === null || $dayIndex < 0 || $date !== null) {
                throw ValidationException::withMessages([
                    'day_index' => 'Reusable plan days require a non-negative day index and no date.',
                ]);
            }

            return;
        }

        if ($dayIndex !== null || $date === null) {
            throw ValidationException::withMessages([
                'date' => 'Dated plan days require a date and no day index.',
            ]);
        }

        if ($date->lessThan($mealPlan->starts_on) || $date->greaterThan($mealPlan->ends_on)) {
            throw ValidationException::withMessages([
                'date' => 'The date must fall within the meal plan date range.',
            ]);
        }
    }
}
