<?php

namespace App\Domain\MealPlans;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditSubjectType;
use App\Domain\Catalogue\CatalogueItemStatus;
use App\Domain\Catalogue\CatalogueNutrientReadModel;
use App\Domain\Measurements\StandardUnit;
use App\Domain\Nutrition\EnergyNormalizer;
use App\Domain\Nutrition\Nutrient;
use App\Domain\Nutrition\NutrientBasis;
use App\Domain\Nutrition\NutrientRegistry;
use App\Models\CatalogueItem;
use App\Models\CatalogueItemVersion;
use App\Models\MealPlanItemEntry;
use App\Models\MealPlanSlot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class MealPlanItemEntryWriter
{
    public function __construct(
        private readonly AuditEventRecorder $audit,
        private readonly EnergyNormalizer $energy,
    ) {}

    public function addCatalogue(
        MealPlanSlot $slot,
        int $catalogueItemId,
        string $plannedAmount,
        StandardUnit $plannedUnit,
        User $actor,
    ): MealPlanItemEntry {
        return DB::transaction(function () use ($slot, $catalogueItemId, $plannedAmount, $plannedUnit, $actor): MealPlanItemEntry {
            $slot = $this->authorizedSlot($slot, $actor);
            $item = CatalogueItem::query()
                ->where('status', CatalogueItemStatus::Approved)
                ->lockForUpdate()
                ->find($catalogueItemId);

            if (! $item instanceof CatalogueItem || $item->current_catalogue_item_version_id === null) {
                throw ValidationException::withMessages([
                    'catalogue_item_id' => 'Only an approved catalogue item with a current version can be added.',
                ]);
            }

            $version = CatalogueItemVersion::query()
                ->with('nutrientValues.sourceObservation')
                ->whereKey($item->current_catalogue_item_version_id)
                ->where('catalogue_item_id', $item->getKey())
                ->first();

            if (! $version instanceof CatalogueItemVersion) {
                throw ValidationException::withMessages([
                    'catalogue_item_id' => 'The approved catalogue item version is unavailable.',
                ]);
            }

            return $this->record($slot, [
                'kind' => MealPlanItemEntryKind::Catalogue,
                'planned_amount' => $plannedAmount,
                'planned_unit' => $plannedUnit,
                'catalogue_item_id' => $item->getKey(),
                'catalogue_item_version_id' => $version->getKey(),
                'catalogue_item_version_number' => $version->version_number,
                'catalogue_snapshot' => $this->catalogueSnapshot($version),
                'catalogue_nutrition_snapshot' => $version->nutrientValues
                    ->map(fn ($value): array => CatalogueNutrientReadModel::fromValue($value)->toArray())
                    ->values()
                    ->all(),
                'one_off_wording' => null,
                'one_off_nutrition' => null,
            ]);
        }, 3);
    }

    /** @param array<string, string|null> $nutrition */
    public function addOneOff(
        MealPlanSlot $slot,
        string $wording,
        string $plannedAmount,
        StandardUnit $plannedUnit,
        ?NutrientBasis $nutritionBasis,
        array $nutrition,
        User $actor,
    ): MealPlanItemEntry {
        return DB::transaction(function () use ($slot, $wording, $plannedAmount, $plannedUnit, $nutritionBasis, $nutrition, $actor): MealPlanItemEntry {
            $slot = $this->authorizedSlot($slot, $actor);
            $enteredValues = [];

            foreach ($nutrition as $identifier => $value) {
                if ($value !== null && trim($value) !== '') {
                    $enteredValues[$identifier] = trim($value);
                }
            }

            $normalizedValues = $this->energy->normalize($enteredValues);
            $values = [];

            foreach ($normalizedValues as $identifier => $value) {
                $nutrient = Nutrient::from($identifier);
                $values[$identifier] = [
                    'value' => (string) $value,
                    'unit' => NutrientRegistry::definition($nutrient)->preferredDisplayUnit->value,
                    'basis' => $nutritionBasis?->value,
                    'status' => 'known',
                    'is_estimate' => false,
                    'source' => $this->oneOffNutritionSource($nutrient, $enteredValues),
                ];
            }

            return $this->record($slot, [
                'kind' => MealPlanItemEntryKind::OneOff,
                'planned_amount' => $plannedAmount,
                'planned_unit' => $plannedUnit,
                'catalogue_item_id' => null,
                'catalogue_item_version_id' => null,
                'catalogue_item_version_number' => null,
                'catalogue_snapshot' => null,
                'catalogue_nutrition_snapshot' => null,
                'one_off_wording' => $wording,
                'one_off_nutrition' => $values === [] ? null : [
                    'source' => 'one_off_user_entry',
                    'basis' => $nutritionBasis?->value,
                    'entered_values' => $enteredValues,
                    'values' => $values,
                ],
            ]);
        }, 3);
    }

    public function move(MealPlanItemEntry $entry, MealPlanSlot $targetSlot, User $actor): MealPlanItemEntry
    {
        return DB::transaction(function () use ($entry, $targetSlot, $actor): MealPlanItemEntry {
            $entry = MealPlanItemEntry::query()->lockForUpdate()->findOrFail($entry->getKey());
            $entry->load('slot.day.mealPlan');
            Gate::forUser($actor)->authorize('update', $entry->slot->day->mealPlan);

            $targetSlot = $this->authorizedSlot($targetSlot, $actor);
            if (! $targetSlot->day->mealPlan->is($entry->slot->day->mealPlan)) {
                throw ValidationException::withMessages([
                    'target_slot_id' => 'Plan entries can only move within their meal plan.',
                ]);
            }

            $entry->slot()->associate($targetSlot);
            $entry->save();

            return $entry;
        }, 3);
    }

    public function remove(MealPlanItemEntry $entry, User $actor): void
    {
        DB::transaction(function () use ($entry, $actor): void {
            $entry = MealPlanItemEntry::query()->lockForUpdate()->findOrFail($entry->getKey());
            $entry->load('slot.day.mealPlan');
            Gate::forUser($actor)->authorize('update', $entry->slot->day->mealPlan);
            $entry->delete();
        }, 3);
    }

    private function authorizedSlot(MealPlanSlot $slot, User $actor): MealPlanSlot
    {
        $slot = MealPlanSlot::query()->lockForUpdate()->findOrFail($slot->getKey());
        $slot->load('day.mealPlan');
        Gate::forUser($actor)->authorize('update', $slot->day->mealPlan);

        return $slot;
    }

    /** @param array<string, mixed> $attributes */
    private function record(MealPlanSlot $slot, array $attributes): MealPlanItemEntry
    {
        $entry = new MealPlanItemEntry;
        $entry->forceFill($attributes);
        $entry->slot()->associate($slot);
        $entry->save();

        $this->audit->record(
            AuditAction::PlanSnapshotRecorded,
            AuditActor::system(),
            AuditSubject::resource(AuditSubjectType::PlanSnapshot, 'plan-item-entry:'.$entry->getKey()),
            ['outcome' => 'recorded', 'snapshot_kind' => 'planned'],
            'plan-item-entry:create:'.$entry->getKey(),
        );

        return $entry;
    }

    /** @return array<string, mixed> */
    private function catalogueSnapshot(CatalogueItemVersion $version): array
    {
        return [
            'name' => $version->name,
            'brand' => $version->brand,
            'manufacturer' => $version->manufacturer,
            'package_count' => $version->package_count,
            'item_type' => $version->item_type,
            'amount_per_item' => $version->amount_per_item,
            'amount_per_item_unit' => $version->getRawOriginal('amount_per_item_unit'),
            'servings_per_item' => $version->servings_per_item,
            'serving_amount' => $version->serving_amount,
            'serving_amount_unit' => $version->getRawOriginal('serving_amount_unit'),
            'serving_amount_basis' => $version->serving_amount_basis?->value,
        ];
    }

    /** @param array<string, string> $enteredValues */
    private function oneOffNutritionSource(Nutrient $nutrient, array $enteredValues): string
    {
        return match ($nutrient) {
            Nutrient::EnergyKcal => array_key_exists(Nutrient::EnergyKcal->value, $enteredValues)
                ? 'one_off_user_entry'
                : 'derived_from_one_off_energy_kj',
            Nutrient::EnergyKj => array_key_exists(Nutrient::EnergyKcal->value, $enteredValues)
                ? 'derived_from_one_off_energy_kcal'
                : 'one_off_user_entry',
            default => 'one_off_user_entry',
        };
    }
}
