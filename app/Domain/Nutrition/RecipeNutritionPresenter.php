<?php

namespace App\Domain\Nutrition;

use App\Domain\Shared\Decimal;
use App\Models\RecipeNutritionOverrideEvent;
use App\Models\RecipeVersion;

final readonly class RecipeNutritionPresenter
{
    public function __construct(
        private RecipeNutritionSourceSelector $selector,
        private RecipeNutritionEstimatePresenter $estimatePresenter,
        private NutrientUnitConverter $converter = new NutrientUnitConverter,
    ) {}

    /** @return array<string, mixed> */
    public function present(RecipeVersion $version, bool $includeHistory = false): array
    {
        $effective = $this->selector->effective($version);
        $source = $effective['source'];
        $primary = $source === RecipeNutritionSource::IngredientEstimate
            ? $this->presentEstimate($version)
            : $this->presentValues($version, $effective['values']);
        $comparisons = [];
        $imported = $this->selector->imported($version);
        if ($source === RecipeNutritionSource::CreatorOverride && $imported !== null) {
            $comparisons[] = ['source' => RecipeNutritionSource::ImportedSource->value,
                'source_label' => RecipeNutritionSource::ImportedSource->label(),
                ...$this->presentValues($version, $imported['per_serving'])];
        }
        if ($source !== RecipeNutritionSource::IngredientEstimate) {
            $comparisons[] = ['source' => RecipeNutritionSource::IngredientEstimate->value,
                'source_label' => RecipeNutritionSource::IngredientEstimate->label(),
                ...$this->presentEstimate($version)];
        }
        $override = $this->selector->currentOverride($version);

        return [...$primary,
            'source' => $source->value,
            'source_label' => $source->label(),
            'is_estimate' => $source === RecipeNutritionSource::IngredientEstimate,
            'provenance' => $effective['provenance'],
            'comparisons' => $comparisons,
            'override_values' => $override?->resulting_values === null ? [] : $this->displayInputs($override->resulting_values),
            'history' => $includeHistory ? $version->nutritionOverrideEvents()
                ->with('actor:id,name')->latest('occurred_at')->latest('id')->get()
                ->map(function (RecipeNutritionOverrideEvent $event): array {
                    $actorName = $event->actor()->value('name');

                    return [
                        'event' => $event->event,
                        'prior_source' => $event->prior_source->label(),
                        'resulting_source' => $event->resulting_source->label(),
                        'occurred_at' => $event->occurred_at,
                        'actor' => is_string($actorName) ? $actorName : 'Deleted account',
                        'note' => $event->note,
                    ];
                })->all() : []];
    }

    /** @return array<string, mixed> */
    private function presentEstimate(RecipeVersion $version): array
    {
        return $this->estimatePresenter->present([
            ...$version->snapshot,
            'nutrition_estimate' => $this->selector->estimate($version),
        ]);
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function presentValues(RecipeVersion $version, array $values): array
    {
        $servings = Decimal::parse((string) ($version->snapshot['servings'] ?? '1'));
        $whole = [];
        foreach ($values as $nutrient => $fact) {
            if (is_array($fact) && isset($fact['value'])) {
                $whole[$nutrient] = [...$fact,
                    'value' => Decimal::forStorage(Decimal::parse((string) $fact['value'])->multipliedBy($servings)),
                    'basis' => NutrientBasis::PerRecipe->value];
            }
        }

        return $this->estimatePresenter->present(['ingredients' => [],
            'nutrition_estimate' => ['whole_recipe' => $whole, 'per_serving' => $values, 'inputs' => []]]);
    }

    /** @param array<string, mixed> $values @return array<string, string> */
    private function displayInputs(array $values): array
    {
        $result = [];
        foreach ($values as $key => $fact) {
            if (! is_array($fact) || ! isset($fact['value'])) {
                continue;
            }
            $nutrient = Nutrient::tryFrom($key);
            if ($nutrient === null || $nutrient === Nutrient::EnergyKj) {
                continue;
            }
            $value = Decimal::parse((string) $fact['value']);
            if ($nutrient === Nutrient::Sodium) {
                $value = $this->converter->convert($value, NutrientUnit::Gram, NutrientUnit::Milligram);
            }
            $result[$key] = rtrim(rtrim((string) $value, '0'), '.');
        }

        return $result;
    }
}
