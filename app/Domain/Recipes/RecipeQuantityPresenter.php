<?php

namespace App\Domain\Recipes;

use App\Domain\Measurements\MeasurementUnitRegistry;
use App\Domain\Measurements\StandardUnit;
use InvalidArgumentException;

final readonly class RecipeQuantityPresenter
{
    public function __construct(
        private RecipeQuantityScaler $scaler = new RecipeQuantityScaler,
        private RecipeQuantityFormatter $formatter = new RecipeQuantityFormatter,
    ) {}

    /**
     * @param  list<array{original_text: string, quantity: string|null, standard_unit: string|null, custom_unit: string|null, generic_wording: string|null, notes: string|null}>  $ingredients
     */
    public function present(
        ?string $originalServings,
        ?string $requestedServings,
        array $ingredients,
        ?string $requestError = null,
    ): RecipeQuantityDisplay {
        if ($originalServings === null) {
            return $this->unavailable($originalServings, $ingredients, $requestError);
        }

        $requestedServings ??= $originalServings;

        try {
            $displayServings = $this->formatter->format($requestedServings);
            $originalDisplayServings = $this->formatter->format($originalServings);
            $presented = array_map(
                fn (array $ingredient): array => $this->presentIngredient(
                    $ingredient,
                    $originalServings,
                    $requestedServings,
                ),
                $ingredients,
            );
        } catch (InvalidArgumentException|InvalidOriginalRecipeServings) {
            return $this->unavailable($originalServings, $ingredients, $requestError);
        }

        return new RecipeQuantityDisplay(
            originalServings: $originalDisplayServings,
            requestedServings: $displayServings,
            ingredients: $presented,
            canResize: true,
            error: $requestError,
        );
    }

    /**
     * @param  array{original_text: string, quantity: string|null, standard_unit: string|null, custom_unit: string|null, generic_wording: string|null, notes: string|null}  $ingredient
     * @return array{original_text: string, structured: bool, quantity: string|null, unit: string|null, generic_wording: string|null, notes: string|null}
     */
    private function presentIngredient(
        array $ingredient,
        string $originalServings,
        string $requestedServings,
    ): array {
        $unit = $this->unit($ingredient['standard_unit'], $ingredient['custom_unit']);
        $wording = $ingredient['generic_wording'];

        if ($ingredient['quantity'] === null || $unit === null || $wording === null || trim($wording) === '') {
            return $this->unchangedIngredient($ingredient);
        }

        return [
            'original_text' => $ingredient['original_text'],
            'structured' => true,
            'quantity' => $this->formatter->format($this->scaler->scale(
                $ingredient['quantity'],
                $originalServings,
                $requestedServings,
            )),
            'unit' => $unit,
            'generic_wording' => $wording,
            'notes' => $ingredient['notes'],
        ];
    }

    private function unit(?string $standardUnit, ?string $customUnit): ?string
    {
        if ($standardUnit !== null && $customUnit !== null) {
            return null;
        }

        if ($standardUnit !== null) {
            $unit = StandardUnit::tryFrom($standardUnit);

            return $unit === null ? null : MeasurementUnitRegistry::definition($unit)->symbol;
        }

        return $customUnit === null || trim($customUnit) === '' ? null : $customUnit;
    }

    /**
     * @param  list<array{original_text: string, quantity: string|null, standard_unit: string|null, custom_unit: string|null, generic_wording: string|null, notes: string|null}>  $ingredients
     */
    private function unavailable(
        ?string $originalServings,
        array $ingredients,
        ?string $requestError,
    ): RecipeQuantityDisplay {
        return new RecipeQuantityDisplay(
            originalServings: $originalServings,
            requestedServings: $originalServings,
            ingredients: array_map($this->unchangedIngredient(...), $ingredients),
            canResize: false,
            error: $requestError ?? (new InvalidOriginalRecipeServings)->getMessage(),
        );
    }

    /**
     * @param  array{original_text: string, quantity: string|null, standard_unit: string|null, custom_unit: string|null, generic_wording: string|null, notes: string|null}  $ingredient
     * @return array{original_text: string, structured: bool, quantity: string|null, unit: string|null, generic_wording: string|null, notes: string|null}
     */
    private function unchangedIngredient(array $ingredient): array
    {
        return [
            'original_text' => $ingredient['original_text'],
            'structured' => false,
            'quantity' => null,
            'unit' => null,
            'generic_wording' => null,
            'notes' => null,
        ];
    }
}
