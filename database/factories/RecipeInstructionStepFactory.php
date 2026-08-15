<?php

namespace Database\Factories;

use App\Models\Recipe;
use App\Models\RecipeInstructionSection;
use App\Models\RecipeInstructionStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RecipeInstructionStep> */
class RecipeInstructionStepFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'recipe_id' => Recipe::factory(),
            'section_id' => null,
            'text' => fake()->randomElement([
                'Preheat the oven.',
                'Mix until just combined.',
                'Serve immediately.',
            ]),
            'position' => 0,
        ];
    }

    public function inSection(RecipeInstructionSection $section): static
    {
        return $this->state(fn (): array => [
            'recipe_id' => $section->recipe_id,
            'section_id' => $section->getKey(),
        ]);
    }

    public function unsectioned(): static
    {
        return $this->state(fn (): array => ['section_id' => null]);
    }
}
