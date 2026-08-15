<?php

namespace Database\Factories;

use App\Domain\Recipes\RecipeLifecycle;
use App\Domain\Recipes\RecipeVisibility;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;

/** @extends Factory<Recipe> */
class RecipeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->words(3, true),
            'servings' => null,
            'lifecycle' => RecipeLifecycle::Draft,
            'visibility' => RecipeVisibility::Public,
        ];
    }

    /** @param array<string, mixed> $attributes */
    public function withIngredientLine(array $attributes = []): static
    {
        return $this->has(
            RecipeIngredientLineFactory::new()->state($attributes),
            'ingredientLines'
        );
    }

    public function withIngredientLines(int $count = 3): static
    {
        return $this->has(
            RecipeIngredientLineFactory::new()
                ->count($count)
                ->sequence(fn (Sequence $sequence): array => ['position' => $sequence->index]),
            'ingredientLines'
        );
    }

    /** @param array<string, mixed> $attributes */
    public function withInstructionStep(array $attributes = []): static
    {
        return $this->has(
            RecipeInstructionStepFactory::new()->state($attributes),
            'instructionSteps'
        );
    }

    public function withInstructionSteps(int $count = 3): static
    {
        return $this->has(
            RecipeInstructionStepFactory::new()
                ->count($count)
                ->sequence(fn (Sequence $sequence): array => ['position' => $sequence->index]),
            'instructionSteps'
        );
    }

    /** @param array<string, mixed> $attributes */
    public function withInstructionSection(array $attributes = []): static
    {
        return $this->has(
            RecipeInstructionSectionFactory::new()->state($attributes),
            'instructionSections'
        );
    }

    public function withInstructionSections(int $count = 2): static
    {
        return $this->has(
            RecipeInstructionSectionFactory::new()
                ->count($count)
                ->sequence(fn (Sequence $sequence): array => ['position' => $sequence->index]),
            'instructionSections'
        );
    }
}
