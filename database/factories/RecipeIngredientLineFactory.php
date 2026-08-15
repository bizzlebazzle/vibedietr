<?php

namespace Database\Factories;

use App\Domain\Measurements\StandardUnit;
use App\Models\Recipe;
use App\Models\RecipeIngredientLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RecipeIngredientLine> */
class RecipeIngredientLineFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'recipe_id' => Recipe::factory(),
            'original_text' => fake()->randomElement(['salt to taste', '2 onions', 'a handful of parsley']),
            'position' => 0,
            'quantity' => null,
            'standard_unit' => null,
            'custom_unit' => null,
            'generic_wording' => null,
            'notes' => null,
        ];
    }

    public function structured(): static
    {
        return $this->state(fn (): array => [
            'original_text' => '2 tbsp olive oil',
            'quantity' => '2',
            'standard_unit' => StandardUnit::Tablespoon,
            'generic_wording' => 'olive oil',
        ]);
    }

    public function unparseable(): static
    {
        return $this->state(fn (): array => [
            'original_text' => 'salt to taste',
            'quantity' => null,
            'standard_unit' => null,
            'custom_unit' => null,
        ]);
    }

    public function customUnit(string $unit = 'handful'): static
    {
        return $this->state(fn (): array => [
            'original_text' => "a {$unit} of parsley",
            'standard_unit' => null,
            'custom_unit' => $unit,
            'generic_wording' => 'parsley',
        ]);
    }

    public function withNotes(string $notes = 'finely chopped'): static
    {
        return $this->state(fn (): array => ['notes' => $notes]);
    }
}
