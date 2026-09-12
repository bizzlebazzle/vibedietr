<?php

namespace App\Domain\Nutrition;

final readonly class RecipeNutritionEstimatePresenter
{
    public function __construct(private NutrientDisplayFormatter $formatter) {}

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{
     *     status: 'complete'|'partial'|'unavailable',
     *     whole_recipe: list<array{label: string, value: string, available: bool}>,
     *     per_serving: list<array{label: string, value: string, available: bool}>,
     *     issues: list<array{position: int, original_text: string, reasons: list<string>}>
     * }
     */
    public function present(array $snapshot): array
    {
        $estimate = is_array($snapshot['nutrition_estimate'] ?? null)
            ? $snapshot['nutrition_estimate']
            : [];
        $ingredients = collect($snapshot['ingredients'] ?? [])->keyBy(
            fn (mixed $ingredient): int => (int) (is_array($ingredient) ? ($ingredient['position'] ?? 0) : 0),
        );
        $inputs = collect($estimate['inputs'] ?? [])->keyBy(
            fn (mixed $input): int => (int) (is_array($input) ? ($input['ingredient_position'] ?? 0) : 0),
        );

        $issues = $ingredients
            ->map(function (mixed $ingredient, int $position) use ($inputs): ?array {
                if (! is_array($ingredient)) {
                    return null;
                }

                $reasons = [];
                $match = $ingredient['catalogue_match'] ?? null;
                if (is_array($match) && ($match['review_state'] ?? null) === 'needs_review') {
                    $reasons[] = 'The selected catalogue match requires creator review.';
                }

                $input = $inputs->get($position);
                if (is_array($input)) {
                    $reasons = [...$reasons, ...$this->exclusionMessages($input['exclusions'] ?? [])];
                }

                if ($reasons === []) {
                    return null;
                }

                return [
                    'position' => $position,
                    'original_text' => (string) ($ingredient['original_text'] ?? ''),
                    'reasons' => array_values(array_unique($reasons)),
                ];
            })
            ->filter()
            ->sortBy('position')
            ->values()
            ->all();

        $wholeRecipe = $this->nutrientRows($estimate['whole_recipe'] ?? []);
        $perServing = $this->nutrientRows($estimate['per_serving'] ?? []);
        $availableCount = collect($wholeRecipe)->where('available', true)->count();
        $status = $availableCount === 0
            ? 'unavailable'
            : ($availableCount === count($wholeRecipe) && $issues === [] ? 'complete' : 'partial');

        return [
            'status' => $status,
            'whole_recipe' => $wholeRecipe,
            'per_serving' => $perServing,
            'issues' => $issues,
        ];
    }

    /**
     * @return list<array{label: string, value: string, available: bool}>
     */
    private function nutrientRows(mixed $values): array
    {
        $values = is_array($values) ? $values : [];

        return array_map(function (NutrientDefinition $definition) use ($values): array {
            $value = $values[$definition->id->value] ?? null;
            if (! is_array($value) || ! array_key_exists('value', $value) || $value['value'] === null) {
                return [
                    'label' => $this->displayLabel($definition),
                    'value' => $this->formatter->format($definition->id, null, NutrientValueStatus::Missing),
                    'available' => false,
                ];
            }

            return [
                'label' => $this->displayLabel($definition),
                'value' => $this->formatter->format(
                    $definition->id,
                    (string) $value['value'],
                    NutrientValueStatus::Approximate,
                ),
                'available' => true,
            ];
        }, NutrientRegistry::all());
    }

    private function displayLabel(NutrientDefinition $definition): string
    {
        return match ($definition->id) {
            Nutrient::EnergyKcal => 'Energy (kcal)',
            Nutrient::EnergyKj => 'Energy (kJ)',
            default => $definition->label,
        };
    }

    /**
     * @return list<string>
     */
    private function exclusionMessages(mixed $exclusions): array
    {
        if (! is_array($exclusions)) {
            return [];
        }

        $grouped = collect($exclusions)
            ->filter(fn (mixed $exclusion): bool => is_array($exclusion) && is_string($exclusion['reason'] ?? null))
            ->groupBy(fn (array $exclusion): string => $exclusion['reason']);

        return $grouped->map(function ($reasonExclusions, string $reason): string {
            $nutrients = $reasonExclusions
                ->pluck('nutrient')
                ->filter(fn (mixed $nutrient): bool => is_string($nutrient))
                ->map(fn (string $nutrient): string => NutrientRegistry::find($nutrient)->label)
                ->unique()
                ->values()
                ->all();
            $suffix = $nutrients === [] ? '' : ' Affected nutrients: '.implode(', ', $nutrients).'.';

            return match ($reason) {
                'quantity_unavailable' => 'The quantity is unavailable, so this line is excluded.',
                'unit_unavailable' => 'The measurement unit is unavailable, so this line is excluded.',
                'catalogue_match_unavailable' => 'No catalogue match is selected, so this line is excluded.',
                'nutrient_value_unavailable' => 'The matched food has no usable value for some nutrients.'.$suffix,
                'multiple_nutrient_bases' => 'The matched food has ambiguous nutrient bases.'.$suffix,
                'unsupported_nutrient_basis' => 'The matched food uses an unsupported nutrient basis.'.$suffix,
                'custom_unit_not_convertible' => 'The custom unit cannot be converted reliably.'.$suffix,
                'unsupported_count_unit' => 'The count unit is not supported for this conversion.'.$suffix,
                'invalid_dimension_combination' => 'The quantity and nutrient basis cannot be converted reliably.'.$suffix,
                'food_conversion_missing' => 'The matched food has no reliable conversion for this quantity.'.$suffix,
                'food_context_not_approved' => 'The food conversion is excluded because the matched food is not approved.'.$suffix,
                'food_conversion_provenance_missing' => 'The food conversion is excluded because its source provenance is unavailable.'.$suffix,
                default => 'This line has an estimate exclusion ('.$reason.').'.$suffix,
            };
        })->values()->all();
    }
}
