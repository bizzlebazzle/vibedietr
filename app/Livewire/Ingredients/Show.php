<?php

namespace App\Livewire\Ingredients;

use App\Domain\Nutrition\Nutrient;
use App\Domain\Nutrition\NutrientDisplayFormatter;
use App\Domain\Nutrition\NutrientRegistry;
use App\Domain\Nutrition\NutrientUnit;
use App\Domain\Nutrition\NutrientUnitConverter;
use App\Domain\Nutrition\NutrientValueStatus;
use App\Models\Ingredient;
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
        $energy = [Nutrient::EnergyKcal, Nutrient::EnergyKj];
        $rows = [[
            'label' => NutrientRegistry::definition(Nutrient::EnergyKcal)->label,
            'nutrients' => $energy,
        ]];

        foreach (NutrientRegistry::all() as $definition) {
            if (in_array($definition->id, $energy, true)) {
                continue;
            }

            $rows[] = [
                'label' => $definition->label,
                'nutrients' => [$definition->id],
            ];
        }

        return $rows;
    }

    /** @param list<Nutrient> $nutrients */
    public function formatNutritionRow(string $bucket, array $nutrients): string
    {
        $amounts = array_map(
            fn (Nutrient $nutrient): ?string => $this->canonicalAmount($bucket, $nutrient),
            $nutrients,
        );

        if (collect($amounts)->every(fn (?string $amount): bool => $amount === null)) {
            return app(NutrientDisplayFormatter::class)->format(
                $nutrients[0],
                null,
                NutrientValueStatus::Missing,
            );
        }

        return collect($nutrients)
            ->map(function (Nutrient $nutrient, int $index) use ($amounts): string {
                $amount = $amounts[$index];

                return app(NutrientDisplayFormatter::class)->format(
                    $nutrient,
                    $amount,
                    $amount === null ? NutrientValueStatus::Missing : NutrientValueStatus::Known,
                );
            })
            ->implode(' / ');
    }

    private function canonicalAmount(string $bucket, Nutrient $nutrient): ?string
    {
        if (in_array($nutrient, [Nutrient::EnergyKcal, Nutrient::EnergyKj], true)) {
            $kcal = data_get($this->ingredient->nutriments, "{$bucket}.".Nutrient::EnergyKcal->value);

            if ($this->isNutrientAmount($kcal)) {
                return (string) $kcal;
            }

            $kilojoules = data_get($this->ingredient->nutriments, "{$bucket}.".Nutrient::EnergyKj->value);

            if (! $this->isNutrientAmount($kilojoules)) {
                return null;
            }

            return (string) app(NutrientUnitConverter::class)->convert(
                (string) $kilojoules,
                NutrientUnit::Kilojoule,
                NutrientUnit::Kilocalorie,
            );
        }

        $amount = data_get($this->ingredient->nutriments, "{$bucket}.{$nutrient->value}");

        return $this->isNutrientAmount($amount) ? (string) $amount : null;
    }

    private function isNutrientAmount(mixed $value): bool
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value));
    }

    public function formatMeasurementValue($value): ?string
    {
        if (! is_numeric($value)) {
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
            ! in_array($unit, $nonPluralShorthandUnits, true)
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
