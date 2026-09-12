<?php

namespace App\Domain\Nutrition;

use App\Domain\Catalogue\CatalogueItemStatus;
use App\Jobs\RecalculateRecipeNutrition;
use App\Models\CatalogueItemVersion;
use App\Models\RecipeNutritionRecalculation;
use App\Models\RecipeVersion;
use App\Queue\CorrelationId;
use Illuminate\Database\QueryException;
use LogicException;

final readonly class RecipeNutritionRecalculationDispatcher
{
    public function __construct(private RecipeNutritionRecalculationDependencies $dependencies) {}

    public function dispatchForApprovedVersion(CatalogueItemVersion $approvedVersion, ?string $correlationId = null): int
    {
        $approvedVersion->loadMissing('catalogueItem');
        if ($approvedVersion->catalogueItem->status !== CatalogueItemStatus::Approved
            || $approvedVersion->catalogueItem->current_catalogue_item_version_id !== $approvedVersion->id) {
            throw new LogicException('Recipe nutrition recalculation requires the current approved catalogue version.');
        }

        $correlationId = CorrelationId::resolve($correlationId);
        $dispatched = 0;

        RecipeVersion::query()
            ->with('currentNutritionRecalculation')
            ->chunkById(200, function ($versions) use ($approvedVersion, $correlationId, &$dispatched): void {
                foreach ($versions as $recipeVersion) {
                    if ($this->dependencies->replacementMap($recipeVersion, $approvedVersion) === null) {
                        continue;
                    }

                    [$recalculation, $created] = $this->operation($recipeVersion, $approvedVersion, $correlationId);
                    if (! in_array($recalculation->state, [
                        RecipeNutritionRecalculationState::Queued,
                        RecipeNutritionRecalculationState::Processing,
                    ], true)) {
                        continue;
                    }

                    RecalculateRecipeNutrition::dispatch(
                        $recalculation->id,
                        $recalculation->recipe_version_id,
                        $recalculation->correlation_id,
                    );
                    if ($created) {
                        $dispatched++;
                    }
                }
            });

        return $dispatched;
    }

    /** @return array{RecipeNutritionRecalculation, bool} */
    private function operation(RecipeVersion $recipeVersion, CatalogueItemVersion $approvedVersion, string $correlationId): array
    {
        $attributes = [
            'recipe_version_id' => $recipeVersion->id,
            'approved_catalogue_item_version_id' => $approvedVersion->id,
        ];
        $existing = RecipeNutritionRecalculation::query()->where($attributes)->first();
        if ($existing !== null) {
            return [$existing, false];
        }

        try {
            return [RecipeNutritionRecalculation::query()->forceCreate([
                ...$attributes,
                'correlation_id' => $correlationId,
                'state' => RecipeNutritionRecalculationState::Queued,
            ]), true];
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }

            return [RecipeNutritionRecalculation::query()->where($attributes)->firstOrFail(), false];
        }
    }
}
