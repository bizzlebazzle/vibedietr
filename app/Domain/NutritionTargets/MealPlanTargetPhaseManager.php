<?php

namespace App\Domain\NutritionTargets;

use App\Domain\MealPlans\MealPlanType;
use App\Models\MealPlan;
use App\Models\MealPlanTargetPhase;
use App\Models\NutritionTarget;
use App\Models\NutritionTargetProfile;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MealPlanTargetPhaseManager
{
    /** @return array{profile: string, targets: array<string, array<string, string|null>>}|null */
    public function resolve(User $owner, MealPlan $plan, string $date): ?array
    {
        $this->assertOwner($owner, $plan);
        $this->assertDated($plan);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))
            || $date < $plan->starts_on->toDateString() || $date > $plan->ends_on->toDateString()) {
            return null;
        }

        $phase = $plan->targetPhases()
            ->whereDate('starts_on', '<=', $date)
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date))
            ->first();

        if ($phase === null) {
            return null;
        }

        $values = $phase->values()->whereDate('effective_on', '<=', $date)
            ->orderByDesc('effective_on')->first();

        return $values === null ? null : [
            'profile' => $phase->profile_name_snapshot,
            'targets' => $values->targets,
        ];
    }

    public function create(User $owner, MealPlan $plan, NutritionTargetProfile $profile, string $start, ?string $end): void
    {
        DB::transaction(function () use ($owner, $plan, $profile, $start, $end): void {
            $this->assertOwner($owner, $plan);
            $profile = $this->ownedProfile($owner, $profile);
            $plan = $this->lockedPlan($owner, $plan);
            $this->validateRange($plan, $start, $end);
            $today = $this->today($owner);
            $tomorrow = $this->nextDay($today);
            $snapshot = $this->snapshot($profile);

            if ($start < $today) {
                $pastEnd = $end === null || $end >= $today ? $this->previousDay($today) : $end;
                if ($pastEnd >= $start) {
                    $this->assertNoOverlap($plan, $start, $pastEnd);
                    $this->insert($plan, $profile, $start, $pastEnd, $snapshot, $start);
                }
            }

            if (($end === null || $end >= $tomorrow) && $tomorrow <= $plan->ends_on->toDateString()) {
                $futureStart = max($start, $tomorrow);
                $this->assertNoOverlap($plan, $futureStart, $end);
                $this->insert($plan, $profile, $futureStart, $end, $snapshot, $today);
            }

            if ($start === $today && ($end === $today || $plan->ends_on->toDateString() === $today)) {
                throw ValidationException::withMessages(['starts_on' => 'A phase assigned today must include a future date.']);
            }
        }, 3);
    }

    public function update(User $owner, MealPlan $plan, MealPlanTargetPhase $phase, NutritionTargetProfile $profile, string $start, ?string $end): void
    {
        DB::transaction(function () use ($owner, $plan, $phase, $profile, $start, $end): void {
            $this->assertOwner($owner, $plan);
            $profile = $this->ownedProfile($owner, $profile);
            $plan = $this->lockedPlan($owner, $plan);
            $phase = $plan->targetPhases()->lockForUpdate()->findOrFail($phase->getKey());
            $this->validateRange($plan, $start, $end);
            $today = $this->today($owner);
            $tomorrow = $this->nextDay($today);

            if ($phase->ends_on !== null && $phase->ends_on->toDateString() <= $today) {
                throw ValidationException::withMessages(['phase' => 'Past target phases cannot be changed.']);
            }
            if ($tomorrow > $plan->ends_on->toDateString() || ($end !== null && $end < $tomorrow)) {
                throw ValidationException::withMessages(['ends_on' => 'A phase edit must include a future date.']);
            }

            $futureStart = max($start, $tomorrow);
            $this->assertNoOverlap($plan, $futureStart, $end, (int) $phase->getKey());

            if ($phase->starts_on->toDateString() <= $today) {
                $phase->forceFill(['ends_on' => $today])->save();
            } else {
                $phase->delete();
            }

            $this->insert($plan, $profile, $futureStart, $end, $this->snapshot($profile), $today);
        }, 3);
    }

    public function remove(User $owner, MealPlan $plan, MealPlanTargetPhase $phase): void
    {
        DB::transaction(function () use ($owner, $plan, $phase): void {
            $plan = $this->lockedPlan($owner, $plan);
            $phase = $plan->targetPhases()->lockForUpdate()->findOrFail($phase->getKey());
            $today = $this->today($owner);

            if ($phase->ends_on !== null && $phase->ends_on->toDateString() <= $today) {
                throw ValidationException::withMessages(['phase' => 'Past target phases cannot be removed.']);
            }

            if ($phase->starts_on->toDateString() <= $today) {
                $phase->forceFill(['ends_on' => $today])->save();
            } else {
                $phase->delete();
            }
        }, 3);
    }

    public function profileUpdated(NutritionTargetProfile $profile, User $owner): void
    {
        $tomorrow = $this->nextDay($this->today($owner));
        $snapshot = $this->snapshot($profile);

        MealPlanTargetPhase::query()->where('nutrition_target_profile_id', $profile->getKey())
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $tomorrow))
            ->get()->each(function (MealPlanTargetPhase $phase) use ($tomorrow, $snapshot): void {
                $phase->values()->updateOrCreate(
                    ['effective_on' => $tomorrow],
                    ['targets' => $snapshot],
                );
            });
    }

    public function profileDeleting(NutritionTargetProfile $profile, User $owner): void
    {
        $today = $this->today($owner);
        MealPlanTargetPhase::query()->where('nutrition_target_profile_id', $profile->getKey())
            ->get()->each(function (MealPlanTargetPhase $phase) use ($today): void {
                if ($phase->starts_on->toDateString() > $today) {
                    $phase->delete();
                } elseif ($phase->ends_on === null || $phase->ends_on->toDateString() > $today) {
                    $phase->forceFill(['ends_on' => $today])->save();
                }
            });
    }

    private function assertOwner(User $owner, MealPlan $plan): void
    {
        abort_unless((int) $owner->getKey() === (int) $plan->user_id, 404);
    }

    private function assertDated(MealPlan $plan): void
    {
        if ($plan->type !== MealPlanType::Dated) {
            throw ValidationException::withMessages(['meal_plan' => 'Target phases require a dated plan.']);
        }
    }

    private function ownedProfile(User $owner, NutritionTargetProfile $profile): NutritionTargetProfile
    {
        return $owner->nutritionTargetProfiles()->lockForUpdate()->findOrFail($profile->getKey());
    }

    private function lockedPlan(User $owner, MealPlan $plan): MealPlan
    {
        return $owner->mealPlans()->lockForUpdate()->findOrFail($plan->getKey());
    }

    private function validateRange(MealPlan $plan, string $start, ?string $end): void
    {
        $this->assertDated($plan);
        if ($start < $plan->starts_on->toDateString() || $start > $plan->ends_on->toDateString()
            || ($end !== null && ($end < $start || $end > $plan->ends_on->toDateString()))) {
            throw ValidationException::withMessages(['starts_on' => 'The phase must fit within the plan dates.']);
        }
    }

    private function assertNoOverlap(MealPlan $plan, string $start, ?string $end, ?int $except = null): void
    {
        $overlap = $plan->targetPhases()
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $start))
            ->when($end !== null, fn ($query) => $query->whereDate('starts_on', '<=', $end))
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages(['starts_on' => 'Target phases cannot overlap.']);
        }
    }

    /** @param array<string, array<string, string|null>> $snapshot */
    private function insert(MealPlan $plan, NutritionTargetProfile $profile, string $start, ?string $end, array $snapshot, string $effective): void
    {
        $phase = $plan->targetPhases()->create([
            'nutrition_target_profile_id' => $profile->getKey(),
            'profile_name_snapshot' => $profile->name,
            'starts_on' => $start,
            'ends_on' => $end,
        ]);
        $phase->values()->create(['effective_on' => $effective, 'targets' => $snapshot]);
    }

    /** @return array<string, array<string, string|null>> */
    private function snapshot(NutritionTargetProfile $profile): array
    {
        return $profile->targets()->get()->mapWithKeys(fn (NutritionTarget $target): array => [
            $target->nutrient => [
                'type' => $target->type->value,
                'exact_value' => $target->exact_value === null ? null : (string) $target->exact_value,
                'minimum_value' => $target->minimum_value === null ? null : (string) $target->minimum_value,
                'maximum_value' => $target->maximum_value === null ? null : (string) $target->maximum_value,
            ],
        ])->all();
    }

    private function today(User $owner): string
    {
        return now($owner->timezone)->toDateString();
    }

    private function nextDay(string $date): string
    {
        return Carbon::parse($date)->addDay()->toDateString();
    }

    private function previousDay(string $date): string
    {
        return Carbon::parse($date)->subDay()->toDateString();
    }
}
