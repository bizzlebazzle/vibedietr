<?php

namespace App\Domain\Nutrition;

use App\Models\RecipeNutritionOverrideEvent;
use App\Models\RecipeVersion;

final class RecipeNutritionSourceSelector
{
    /** @return array{source: RecipeNutritionSource, values: array<string, mixed>, provenance: array<string, mixed>|null} */
    public function effective(RecipeVersion $version): array
    {
        $override = $this->currentOverride($version);
        if ($override?->resulting_values !== null) {
            return [
                'source' => RecipeNutritionSource::CreatorOverride,
                'values' => $override->resulting_values,
                'provenance' => ['override_event_id' => $override->id],
            ];
        }

        $imported = $this->imported($version);
        if ($imported !== null) {
            return [
                'source' => RecipeNutritionSource::ImportedSource,
                'values' => $imported['per_serving'],
                'provenance' => is_array($imported['provenance'] ?? null) ? $imported['provenance'] : null,
            ];
        }

        return [
            'source' => RecipeNutritionSource::IngredientEstimate,
            'values' => $this->estimateValues($version),
            'provenance' => null,
        ];
    }

    public function currentOverride(RecipeVersion $version): ?RecipeNutritionOverrideEvent
    {
        return $version->nutritionOverrideEvents()
            ->latest('occurred_at')
            ->latest('id')
            ->first();
    }

    /** @return array<string, mixed>|null */
    public function imported(RecipeVersion $version): ?array
    {
        $imported = $version->snapshot['imported_nutrition'] ?? null;

        return is_array($imported) && is_array($imported['per_serving'] ?? null)
            && $imported['per_serving'] !== [] ? $imported : null;
    }

    /** @return array<string, mixed> */
    public function estimateValues(RecipeVersion $version): array
    {
        $estimate = $version->snapshot['nutrition_estimate'] ?? null;

        return is_array($estimate) && is_array($estimate['per_serving'] ?? null)
            ? $estimate['per_serving'] : [];
    }
}
