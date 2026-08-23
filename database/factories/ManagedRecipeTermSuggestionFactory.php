<?php

namespace Database\Factories;

use App\Domain\Recipes\ManagedRecipeTermSuggestionSource;
use App\Domain\Recipes\ManagedRecipeTermSuggestionStatus;
use App\Models\ManagedRecipeTerm;
use App\Models\ManagedRecipeTermSuggestion;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ManagedRecipeTermSuggestion> */
class ManagedRecipeTermSuggestionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'recipe_id' => Recipe::factory(),
            'managed_recipe_term_id' => ManagedRecipeTerm::factory(),
            'suggested_by_user_id' => User::factory()->administrator(),
            'source' => ManagedRecipeTermSuggestionSource::Administrator,
            'status' => ManagedRecipeTermSuggestionStatus::Pending,
            'pending_key' => fn (array $attributes): string => $attributes['recipe_id'].':'.$attributes['managed_recipe_term_id'].':administrator',
            'decided_at' => null,
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => [
            'status' => ManagedRecipeTermSuggestionStatus::Accepted,
            'pending_key' => null,
            'decided_at' => now()->utc(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => ManagedRecipeTermSuggestionStatus::Rejected,
            'pending_key' => null,
            'decided_at' => now()->utc(),
        ]);
    }
}
