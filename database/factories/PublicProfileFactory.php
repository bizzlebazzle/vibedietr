<?php

namespace Database\Factories;

use App\Models\PublicProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PublicProfile> */
class PublicProfileFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'attribution_name' => fake()->name(),
            'profile_enabled' => false,
            'show_public_recipes' => false,
            'show_public_remixes' => false,
        ];
    }

    public function enabled(): static
    {
        return $this->state(fn (): array => ['profile_enabled' => true]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['profile_enabled' => false]);
    }

    public function showingPublicRecipes(): static
    {
        return $this->state(fn (): array => ['show_public_recipes' => true]);
    }

    public function showingPublicRemixes(): static
    {
        return $this->state(fn (): array => ['show_public_remixes' => true]);
    }
}
