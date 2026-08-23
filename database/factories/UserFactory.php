<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user is an administrator.
     */
    public function administrator(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_administrator' => true,
        ]);
    }

    public function withPublicAttribution(?string $name = null): static
    {
        return $this->has(
            PublicProfileFactory::new()->state(fn (): array => [
                'attribution_name' => $name ?? fake()->name(),
            ]),
            'publicProfile',
        );
    }

    public function withEnabledPublicProfile(?string $name = null): static
    {
        return $this->has(
            PublicProfileFactory::new()->enabled()->state(fn (): array => [
                'attribution_name' => $name ?? fake()->name(),
            ]),
            'publicProfile',
        );
    }

    public function withPublicRecipeListing(?string $name = null): static
    {
        return $this->has(
            PublicProfileFactory::new()->enabled()->showingPublicRecipes()->state(fn (): array => [
                'attribution_name' => $name ?? fake()->name(),
            ]),
            'publicProfile',
        );
    }

    public function withPublicRemixListing(?string $name = null): static
    {
        return $this->has(
            PublicProfileFactory::new()->enabled()->showingPublicRemixes()->state(fn (): array => [
                'attribution_name' => $name ?? fake()->name(),
            ]),
            'publicProfile',
        );
    }

    public function withDistinctivePrivateEmail(?string $email = null): static
    {
        return $this->state(fn (): array => [
            'email' => $email ?? 'distinctive-private-'.fake()->unique()->uuid().'@example.test',
        ]);
    }

    public function withPrivateRecipe(): static
    {
        return $this->has(
            RecipeFactory::new()->finalizedPrivate(),
            'recipes',
        );
    }

    public function withPrivateRecipeOrganization(): static
    {
        return $this
            ->has(RecipeCollectionFactory::new(), 'recipeCollections')
            ->has(PrivateRecipeTagFactory::new(), 'privateRecipeTags');
    }
}
