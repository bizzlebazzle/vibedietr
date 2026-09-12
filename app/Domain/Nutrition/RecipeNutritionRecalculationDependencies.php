<?php

namespace App\Domain\Nutrition;

use App\Models\CatalogueItemVersion;
use App\Models\RecipeNutritionRecalculation;
use App\Models\RecipeVersion;

final class RecipeNutritionRecalculationDependencies
{
    /** @return array<string, mixed> */
    public function currentEstimate(RecipeVersion $version): array
    {
        $recalculation = $version->relationLoaded('currentNutritionRecalculation')
            ? $version->currentNutritionRecalculation
            : $version->currentNutritionRecalculation()->first();

        if ($recalculation instanceof RecipeNutritionRecalculation && is_array($recalculation->estimate)) {
            return $recalculation->estimate;
        }

        $estimate = $version->snapshot['nutrition_estimate'] ?? null;

        return is_array($estimate) ? $estimate : [];
    }

    /**
     * @return array<int, string>|null Position-keyed dependencies with the approved version substituted.
     */
    public function replacementMap(RecipeVersion $recipeVersion, CatalogueItemVersion $approvedVersion): ?array
    {
        $dependencyMap = $this->dependencyMap($this->currentEstimate($recipeVersion));
        if ($dependencyMap === []) {
            return null;
        }

        $versions = CatalogueItemVersion::query()
            ->whereKey(array_values(array_unique($dependencyMap)))
            ->get(['id', 'catalogue_item_id'])
            ->keyBy('id');
        $affected = false;

        foreach ($dependencyMap as $position => $versionId) {
            $dependency = $versions->get($versionId);
            if ($dependency?->catalogue_item_id === $approvedVersion->catalogue_item_id
                && $versionId !== $approvedVersion->id) {
                $dependencyMap[$position] = $approvedVersion->id;
                $affected = true;
            }
        }

        return $affected ? $dependencyMap : null;
    }

    /** @param array<string, mixed> $estimate @return array<int, string> */
    private function dependencyMap(array $estimate): array
    {
        $dependencies = [];

        foreach ($estimate['inputs'] ?? [] as $input) {
            if (! is_array($input)
                || ! is_int($input['ingredient_position'] ?? null)
                || ! is_string($input['catalogue_item_version_id'] ?? null)) {
                continue;
            }

            $dependencies[$input['ingredient_position']] = $input['catalogue_item_version_id'];
        }

        return $dependencies;
    }
}
