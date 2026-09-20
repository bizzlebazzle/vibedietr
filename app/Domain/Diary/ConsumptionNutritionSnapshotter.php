<?php

namespace App\Domain\Diary;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditSubjectType;
use App\Domain\MealPlans\MealPlanItemEntryKind;
use App\Domain\Nutrition\RecipeNutritionSource;
use App\Models\ConsumptionNutritionSnapshot;
use App\Models\DiaryConsumptionState;
use App\Models\DiaryEntry;
use App\Models\MealPlanItemEntry;
use App\Models\MealPlanRecipeEntry;
use Illuminate\Support\Str;

class ConsumptionNutritionSnapshotter
{
    public function __construct(private readonly AuditEventRecorder $audit) {}

    public function create(
        MealPlanRecipeEntry|MealPlanItemEntry|DiaryEntry $entry,
        DiaryConsumptionState $state,
        string $amount,
        string $unit,
    ): ConsumptionNutritionSnapshot {
        $attributes = $this->attributes($entry);
        $id = (string) Str::ulid();
        $audit = $this->audit->record(
            AuditAction::PlanSnapshotRecorded,
            AuditActor::system(),
            AuditSubject::resource(AuditSubjectType::PlanSnapshot, 'consumption-snapshot:'.$id),
            ['outcome' => 'recorded', 'snapshot_kind' => 'consumed'],
            'consumption-snapshot:create:'.$id,
        );
        $snapshot = new ConsumptionNutritionSnapshot;
        $snapshot->forceFill([
            'id' => $id,
            'diary_consumption_state_id' => $state->getKey(),
            'audit_event_id' => $audit->getKey(),
            'actual_amount' => $amount,
            'actual_unit' => $unit,
            ...$attributes,
        ])->save();

        return $snapshot;
    }

    /** @return array<string, mixed> */
    private function attributes(MealPlanRecipeEntry|MealPlanItemEntry|DiaryEntry $entry): array
    {
        if ($entry instanceof MealPlanRecipeEntry) {
            return $this->snapshotAttributes(
                'meal_plan_recipe_entry',
                $entry->getKey(),
                DiaryEntryKind::Recipe,
                $entry->recipe_id,
                $entry->recipe_version_id,
                $entry->recipe_version_number,
                $entry->nutrition_snapshot,
            );
        }

        if ($entry instanceof MealPlanItemEntry) {
            $isCatalogue = $entry->kind === MealPlanItemEntryKind::Catalogue;

            return $this->snapshotAttributes(
                'meal_plan_item_entry',
                $entry->getKey(),
                $isCatalogue ? DiaryEntryKind::Catalogue : DiaryEntryKind::OneOff,
                $isCatalogue ? $entry->catalogue_item_id : null,
                $isCatalogue ? $entry->catalogue_item_version_id : null,
                $isCatalogue ? $entry->catalogue_item_version_number : null,
                $isCatalogue ? $entry->catalogue_nutrition_snapshot : $entry->one_off_nutrition,
            );
        }

        $nutrition = match ($entry->kind) {
            DiaryEntryKind::Recipe => $entry->source_snapshot['nutrition'] ?? null,
            DiaryEntryKind::Catalogue => $entry->source_snapshot['nutrition'] ?? null,
            DiaryEntryKind::OneOff => $this->diaryOneOffNutrition($entry->source_snapshot),
        };

        return $this->snapshotAttributes(
            'diary_entry',
            $entry->getKey(),
            $entry->kind,
            $entry->source_id,
            $entry->source_version_id,
            $entry->source_version_number,
            $nutrition,
        );
    }

    /**
     * @param  array<string, mixed>|list<array<string, mixed>>|null  $nutrition
     * @return array<string, mixed>
     */
    private function snapshotAttributes(string $entryType, int $entryId, DiaryEntryKind $kind, ?int $sourceId, ?string $versionId, ?int $versionNumber, ?array $nutrition): array
    {
        return [
            'source_entry_type' => $entryType,
            'source_entry_id' => $entryId,
            'item_kind' => $kind->value,
            'source_id' => $sourceId,
            'source_version_id' => $versionId,
            'source_version_number' => $versionNumber,
            'nutrition_source' => $this->nutritionSource($kind, $nutrition),
            'is_estimate' => $this->isEstimate($kind, $nutrition),
            'nutrition' => $nutrition,
        ];
    }

    /** @param array<string, mixed>|list<array<string, mixed>>|null $nutrition */
    private function nutritionSource(DiaryEntryKind $kind, ?array $nutrition): ?string
    {
        if (is_string($nutrition['source'] ?? null)) {
            return $nutrition['source'];
        }

        return match ($kind) {
            DiaryEntryKind::Catalogue => 'catalogue_version',
            DiaryEntryKind::OneOff => $nutrition === null ? null : 'one_off_user_entry',
            DiaryEntryKind::Recipe => null,
        };
    }

    /** @param array<string, mixed>|list<array<string, mixed>>|null $nutrition */
    private function isEstimate(DiaryEntryKind $kind, ?array $nutrition): bool
    {
        if ($kind === DiaryEntryKind::Recipe && ($nutrition['source'] ?? null) === RecipeNutritionSource::IngredientEstimate->value) {
            return true;
        }

        $selectedValues = is_array($nutrition['values'] ?? null) ? $nutrition['values'] : $nutrition;

        return $this->containsEstimate($selectedValues);
    }

    /** @param array<string, mixed>|list<array<string, mixed>>|null $values */
    private function containsEstimate(?array $values): bool
    {
        foreach ($values ?? [] as $value) {
            if (is_array($value) && (($value['is_estimate'] ?? false) === true || $this->containsEstimate($value))) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $source @return array<string, mixed>|null */
    private function diaryOneOffNutrition(array $source): ?array
    {
        if (($source['entered_nutrition'] ?? []) === [] && ($source['normalized_nutrition'] ?? []) === []) {
            return null;
        }

        return [
            'source' => 'one_off_user_entry',
            'basis' => $source['nutrition_basis'] ?? null,
            'entered_values' => $source['entered_nutrition'] ?? [],
            'values' => $source['normalized_nutrition'] ?? [],
        ];
    }
}
