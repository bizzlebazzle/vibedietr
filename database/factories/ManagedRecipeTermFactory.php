<?php

namespace Database\Factories;

use App\Domain\Recipes\ManagedRecipeTermCategory;
use App\Models\ManagedRecipeTerm;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ManagedRecipeTerm> */
class ManagedRecipeTermFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'category' => fake()->randomElement(ManagedRecipeTermCategory::cases()),
            'name' => fake()->unique()->word(),
            'is_active' => true,
        ];
    }

    public function dietary(): static
    {
        return $this->state(fn (): array => ['category' => ManagedRecipeTermCategory::Dietary]);
    }

    public function cuisine(): static
    {
        return $this->state(fn (): array => ['category' => ManagedRecipeTermCategory::Cuisine]);
    }

    public function mealType(): static
    {
        return $this->state(fn (): array => ['category' => ManagedRecipeTermCategory::MealType]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
