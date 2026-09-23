<?php

namespace Database\Factories;

use App\Models\NutritionTargetProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NutritionTargetProfile> */
class NutritionTargetProfileFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'is_default' => null,
        ];
    }
}
