<?php

namespace App\Domain\NutritionTargets;

use App\Domain\Measurements\Exceptions\IncompatibleDimensions;
use App\Domain\Measurements\Exceptions\NonConvertibleUnit;
use App\Domain\Measurements\StandardUnit;
use App\Domain\Measurements\UnitConverter;
use App\Domain\Nutrition\Nutrient;
use App\Domain\Nutrition\NutrientBasis;
use App\Domain\Nutrition\NutrientDisplayFormatter;
use App\Domain\Nutrition\NutrientRegistry;
use App\Domain\Nutrition\NutrientUnit;
use App\Domain\Nutrition\NutrientUnitConverter;
use App\Domain\Shared\Decimal;
use App\Models\ConsumptionNutritionSnapshot;
use App\Models\DiaryConsumptionState;
use App\Models\DiaryConsumptionTransition;
use App\Models\MealPlan;
use App\Models\MealPlanDay;
use App\Models\User;
use Brick\Math\BigDecimal;
use LogicException;

final readonly class DailyNutritionComparison
{
    public function __construct(
        private MealPlanTargetPhaseManager $phases,
        private NutrientDisplayFormatter $formatter,
        private NutrientUnitConverter $nutrientUnits,
        private UnitConverter $units,
    ) {}

    /** @return array{profile: string|null, rows: list<array<string, mixed>>} */
    public function forDay(User $owner, MealPlan $plan, MealPlanDay $day): array
    {
        abort_unless((int) $owner->getKey() === (int) $plan->user_id && (int) $day->meal_plan_id === (int) $plan->getKey(), 404);
        $date = $day->date?->toDateString();
        if ($date === null) {
            return ['profile' => null, 'rows' => []];
        }

        $phase = $this->phases->resolve($owner, $plan, $date);
        $planned = [];
        foreach ($day->slots as $slot) {
            foreach ($slot->recipeEntries as $entry) {
                $planned[] = [$entry->nutrition_snapshot, $entry->planned_servings, StandardUnit::Serving];
            }
            foreach ($slot->itemEntries as $entry) {
                $planned[] = [$entry->kind->value === 'catalogue' ? $entry->catalogue_nutrition_snapshot : $entry->one_off_nutrition,
                    $entry->planned_amount, $entry->planned_unit];
            }
        }

        // Current pointers select effective history; superseded and reversed episodes never contribute.
        $states = DiaryConsumptionState::query()
            ->whereHas('currentTransition', fn ($query) => $query->whereDate('effective_diary_date', $date))
            ->where(function ($query) use ($owner, $plan): void {
                $query->whereHas('recipeEntry.slot.day', fn ($dayQuery) => $dayQuery->where('meal_plan_id', $plan->getKey()))
                    ->orWhereHas('itemEntry.slot.day', fn ($dayQuery) => $dayQuery->where('meal_plan_id', $plan->getKey()))
                    ->orWhereHas('diaryEntry', fn ($diaryQuery) => $diaryQuery->where('user_id', $owner->getKey()));
            })
            ->with('currentTransition.nutritionSnapshot')
            ->get();
        $consumed = [];
        foreach ($states as $state) {
            $transition = $state->currentTransition;
            $snapshot = $transition instanceof DiaryConsumptionTransition ? $transition->nutritionSnapshot : null;
            if (! $snapshot instanceof ConsumptionNutritionSnapshot || $transition->actual_amount === null || $transition->actual_unit === null) {
                $consumed[] = [null, '1', StandardUnit::Serving];

                continue;
            }
            $consumed[] = [$snapshot->nutrition, $snapshot->actual_amount, StandardUnit::from($snapshot->actual_unit)];
        }

        $rows = [];
        foreach (NutrientRegistry::all() as $definition) {
            $nutrient = $definition->id;
            if (in_array($nutrient, [Nutrient::EnergyKj, Nutrient::Sodium], true)
                && ! isset($phase['targets'][$nutrient->value])
                && ! $this->hasNutrient($planned, $nutrient) && ! $this->hasNutrient($consumed, $nutrient)) {
                continue;
            }
            $target = $phase['targets'][$nutrient->value] ?? null;
            $plannedTotal = $this->total($planned, $nutrient);
            $consumedTotal = $this->total($consumed, $nutrient);
            $rows[] = [
                'label' => match ($nutrient) {
                    Nutrient::EnergyKcal => 'Energy (kcal)',
                    Nutrient::EnergyKj => 'Energy (kJ)',
                    default => $definition->label,
                },
                'target' => $target === null ? 'No target' : $this->targetLabel($nutrient, $target),
                'planned' => $this->present($nutrient, $plannedTotal, $target),
                'consumed' => $this->present($nutrient, $consumedTotal, $target),
            ];
        }

        return ['profile' => $phase['profile'] ?? null, 'rows' => $rows];
    }

    /** @param list<array{mixed, mixed, mixed}> $entries */
    private function hasNutrient(array $entries, Nutrient $nutrient): bool
    {
        foreach ($entries as [$nutrition]) {
            if ($this->fact($nutrition, $nutrient) !== null) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{mixed, mixed, mixed}> $entries @return array{amount: BigDecimal|null, partial: bool, estimate: bool} */
    private function total(array $entries, Nutrient $nutrient): array
    {
        $sum = null;
        $partial = false;
        $estimate = false;
        foreach ($entries as [$nutrition, $amount, $unit]) {
            $fact = $this->fact($nutrition, $nutrient);
            $value = $fact === null ? null : $this->amount($fact, (string) $amount, $unit, $nutrient);
            if ($value === null) {
                $partial = true;

                continue;
            }
            $sum = $sum === null ? $value : $sum->plus($value);
            // Daily totals scale source nutrition by quantities and are estimates.
            $estimate = true;
        }

        return ['amount' => $sum, 'partial' => $partial, 'estimate' => $estimate];
    }

    /** @return array<string, mixed>|null */
    private function fact(mixed $nutrition, Nutrient $nutrient): ?array
    {
        if (! is_array($nutrition)) {
            return null;
        }
        $values = is_array($nutrition['values'] ?? null) ? $nutrition['values'] : $nutrition;
        if (array_is_list($values)) {
            foreach ($values as $fact) {
                if (is_array($fact) && ($fact['nutrient'] ?? null) === $nutrient->value) {
                    return $fact;
                }
            }

            return null;
        }
        $fact = $values[$nutrient->value] ?? null;
        if (is_string($fact) || is_int($fact)) {
            return ['value' => (string) $fact, 'unit' => NutrientRegistry::definition($nutrient)->preferredDisplayUnit->value,
                'basis' => $nutrition['basis'] ?? null, 'status' => 'known'];
        }

        return is_array($fact) ? $fact : null;
    }

    /** @param array<string, mixed> $fact */
    private function amount(array $fact, string $quantity, StandardUnit $quantityUnit, Nutrient $nutrient): ?BigDecimal
    {
        if (! in_array($fact['status'] ?? null, ['known', 'approximate'], true)
            || ! isset($fact['value'], $fact['unit'], $fact['basis'])) {
            return null;
        }
        $unit = NutrientUnit::tryFrom((string) $fact['unit']);
        $basis = NutrientBasis::tryFrom((string) $fact['basis']);
        if ($unit === null || $basis === null) {
            return null;
        }
        $baseUnit = match ($basis) {
            NutrientBasis::Per100Gram => StandardUnit::Gram,
            NutrientBasis::Per100Millilitre => StandardUnit::Millilitre,
            NutrientBasis::PerServing => StandardUnit::Serving,
            NutrientBasis::PerItem => StandardUnit::Item,
            default => null,
        };
        if ($baseUnit === null) {
            return null;
        }
        try {
            $converted = $this->units->convert($quantity, $quantityUnit, $baseUnit);
        } catch (IncompatibleDimensions|NonConvertibleUnit) {
            return null;
        }
        $divisor = in_array($basis, [NutrientBasis::Per100Gram, NutrientBasis::Per100Millilitre], true) ? '100' : '1';
        $canonical = NutrientRegistry::definition($nutrient)->canonicalStorageUnit;

        return $this->nutrientUnits->convert((string) $fact['value'], $unit, $canonical)
            ->multipliedBy($converted)->dividedBy($divisor, Decimal::DIVISION_GUARD_SCALE, Decimal::ROUNDING_MODE);
    }

    /** @param array<string, string|null> $target */
    private function targetLabel(Nutrient $nutrient, array $target): string
    {
        $format = fn (string $value): string => $this->formatter->format($nutrient, $value);

        return match ($target['type']) {
            'exact' => 'Exact '.$format($target['exact_value']),
            'minimum' => 'At least '.$format($target['minimum_value']),
            'maximum' => 'At most '.$format($target['maximum_value']),
            'range' => $format($target['minimum_value']).' to '.$format($target['maximum_value']),
            default => throw new LogicException('Unsupported nutrition target type.'),
        };
    }

    /** @param array{amount: BigDecimal|null, partial: bool, estimate: bool} $total @param array<string, string|null>|null $target @return array{value: string, status: string, note: string} */
    private function present(Nutrient $nutrient, array $total, ?array $target): array
    {
        $amount = $total['amount'];
        if ($amount === null) {
            return ['value' => 'Not available', 'status' => 'Not available', 'note' => ''];
        }
        $status = $target === null ? 'No target' : ($total['partial'] ? 'Comparison unavailable' : $this->classification($amount, $target));
        $note = implode('; ', array_filter([$total['partial'] ? 'Partial total; some nutrition is unavailable' : null,
            $total['estimate'] ? 'Estimate' : null]));

        return ['value' => $this->formatter->format($nutrient, (string) $amount), 'status' => $status, 'note' => $note];
    }

    /** @param array<string, string|null> $target */
    private function classification(BigDecimal $amount, array $target): string
    {
        $lower = isset($target['minimum_value']) ? Decimal::parse($target['minimum_value']) : null;
        $upper = isset($target['maximum_value']) ? Decimal::parse($target['maximum_value']) : null;

        return match ($target['type']) {
            'exact' => match ($amount->compareTo(Decimal::parse($target['exact_value']))) {
                -1 => 'Below target', 0 => 'At target', 1 => 'Above target',
            },
            'minimum' => $amount->isLessThan($lower) ? 'Below minimum' : 'Meets minimum',
            'maximum' => $amount->isGreaterThan($upper) ? 'Above maximum' : 'Meets maximum',
            'range' => $amount->isLessThan($lower) ? 'Below range'
                : ($amount->isGreaterThan($upper) ? 'Above range' : 'Within range'),
            default => throw new LogicException('Unsupported nutrition target type.'),
        };
    }
}
