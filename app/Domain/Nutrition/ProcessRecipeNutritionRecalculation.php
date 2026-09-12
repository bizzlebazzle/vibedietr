<?php

namespace App\Domain\Nutrition;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditSubjectType;
use App\Domain\Catalogue\CatalogueItemStatus;
use App\Models\RecipeNutritionRecalculation;
use App\Models\RecipeVersion;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

final readonly class ProcessRecipeNutritionRecalculation
{
    public function __construct(
        private RecipeNutritionRecalculationDependencies $dependencies,
        private RecipeNutritionEstimator $estimator,
        private AuditEventRecorder $audit,
    ) {}

    public function process(string $recalculationId): void
    {
        DB::transaction(function () use ($recalculationId): void {
            $recalculation = RecipeNutritionRecalculation::query()->lockForUpdate()->find($recalculationId);
            if ($recalculation === null
                || in_array($recalculation->state, [
                    RecipeNutritionRecalculationState::Completed,
                    RecipeNutritionRecalculationState::Skipped,
                ], true)) {
                return;
            }

            $recalculation->forceFill([
                'state' => RecipeNutritionRecalculationState::Processing,
                'failure_code' => null,
                'started_at' => $recalculation->started_at ?? Date::now()->utc(),
            ])->save();
            $approvedVersion = $recalculation->approvedCatalogueItemVersion()
                ->with('catalogueItem')
                ->firstOrFail();
            $recipeVersion = RecipeVersion::query()->lockForUpdate()->findOrFail($recalculation->recipe_version_id);

            if ($approvedVersion->catalogueItem->status !== CatalogueItemStatus::Approved
                || $approvedVersion->catalogueItem->current_catalogue_item_version_id !== $approvedVersion->id) {
                $recalculation->forceFill([
                    'state' => RecipeNutritionRecalculationState::Skipped,
                    'completed_at' => Date::now()->utc(),
                ])->save();

                return;
            }

            $dependencyMap = $this->dependencies->replacementMap($recipeVersion, $approvedVersion);
            if ($dependencyMap === null) {
                $recalculation->forceFill([
                    'state' => RecipeNutritionRecalculationState::Skipped,
                    'completed_at' => Date::now()->utc(),
                ])->save();

                return;
            }

            $estimate = $this->estimator->estimateVersion($recipeVersion, $dependencyMap);
            $event = $this->audit->record(
                AuditAction::RecipeNutritionRecalculated,
                AuditActor::system(),
                AuditSubject::resource(AuditSubjectType::NutritionCalculation, $recalculation->id),
                [
                    'approved_catalogue_version_id' => $approvedVersion->id,
                    'outcome' => 'recalculated',
                    'recipe_version_id' => $recipeVersion->id,
                ],
                correlationId: $recalculation->correlation_id,
                evidenceReference: $recalculation->id,
            );
            $recalculation->forceFill([
                'state' => RecipeNutritionRecalculationState::Completed,
                'estimate' => $estimate,
                'audit_event_id' => $event->id,
                'completed_at' => Date::now()->utc(),
            ])->save();
        }, 3);
    }
}
