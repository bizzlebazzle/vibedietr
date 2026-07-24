<?php

namespace App\Livewire\Ingredients;

use App\Models\Ingredient;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Component;

class Show extends Component
{
    public Ingredient $ingredient;
    public bool $withinModal = false;

    public function mount(Ingredient $ingredient, bool $withinModal = false): void
    {
        $this->authorize('view', $ingredient);

        $this->ingredient = $ingredient;
        $this->withinModal = $withinModal;
    }

    protected function nutritionPanels(): array
    {
        return [
            [
                'title' => 'Per 100g',
                'bucket' => 'per_100g',
            ],
            [
                'title' => 'Per serving',
                'bucket' => 'per_serving',
            ],
        ];
    }

    protected function nutritionRows(): array
    {
        return [
            ['label' => 'Energy', 'keys' => ['energy_kj' => 'kJ', 'energy_kcal' => 'kcal'], 'integer' => true],
            ['label' => 'Fat', 'keys' => ['fat' => 'g']],
            ['label' => 'Saturates', 'keys' => ['saturated_fat' => 'g']],
            ['label' => 'Sugars', 'keys' => ['sugars' => 'g']],
            ['label' => 'Salt', 'keys' => ['salt' => 'g']],
        ];
    }

    public function formatNutritionValue($value, bool $integer = false): ?string
    {
        if (!is_numeric($value)) {
            return null;
        }

        if ($integer) {
            return (string) round((float) $value);
        }

        return Number::format((float) $value, 2);
    }

    public function formatMeasurementValue($value): ?string
    {
        if (!is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        if (floor($number) === $number) {
            return (string) (int) $number;
        }

        return rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.');
    }

    public function displayUnitLabel(?string $unit, $value = null): ?string
    {
        $unit = trim((string) $unit);

        if ($unit === '') {
            return null;
        }

        $nonPluralShorthandUnits = [
            'mg', 'g', 'kg', 'ml', 'cl', 'l',
            'tsp', 'tbsp', 'fl oz', 'cup',
            'pt', 'qt', 'gal', 'oz', 'lb',
        ];

        if (
            !in_array($unit, $nonPluralShorthandUnits, true)
            && preg_match('/^[a-z]+$/i', $unit)
            && is_numeric($value)
            && (float) $value !== 1.0
        ) {
            return Str::plural($unit);
        }

        return $unit;
    }

    public function formatMeasurement($value, ?string $unit = null): ?string
    {
        $formattedValue = $this->formatMeasurementValue($value);

        if ($formattedValue === null) {
            return null;
        }

        $formattedUnit = $this->displayUnitLabel($unit, $value);

        if ($formattedUnit === null) {
            return $formattedValue;
        }

        $separator = in_array($unit, ['mg', 'g', 'kg', 'ml', 'cl', 'l'], true) ? '' : ' ';

        return $formattedValue.$separator.$formattedUnit;
    }

    public function render()
    {
        return view('livewire.ingredients.show', [
            'nutritionPanels' => $this->nutritionPanels(),
            'nutritionRows' => $this->nutritionRows(),
        ]);
    }
}
