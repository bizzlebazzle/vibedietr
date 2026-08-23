<?php

namespace Database\Factories;

use App\Domain\Recipes\RecipeLifecycle;
use App\Domain\Recipes\RecipeVisibility;
use App\Models\PublicProfile;
use App\Models\Recipe;
use App\Models\RecipeDraftRevision;
use App\Models\RecipeVersion;
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

    public function validDraft(): static
    {
        return $this->state(fn (): array => [
            'title' => 'Valid draft',
            'servings' => '2.00',
            'lifecycle' => RecipeLifecycle::Draft,
        ])->withIngredientLine()->withInstructionStep();
    }

    public function missingTitle(): static
    {
        return $this->validDraft()->state(fn (): array => ['title' => '']);
    }

    public function zeroServings(): static
    {
        return $this->validDraft()->state(fn (): array => ['servings' => '0']);
    }

    public function withoutIngredientLines(): static
    {
        return $this->state(fn (): array => ['title' => 'No ingredients', 'servings' => '2.00'])
            ->withInstructionStep();
    }

    public function withoutInstructionSteps(): static
    {
        return $this->state(fn (): array => ['title' => 'No instructions', 'servings' => '2.00'])
            ->withIngredientLine();
    }

    public function finalizedPublic(): static
    {
        return $this->finalized(RecipeVisibility::Public);
    }

    public function finalizedPrivate(): static
    {
        return $this->finalized(RecipeVisibility::Private);
    }

    public function finalizedWithActiveRevision(RecipeVisibility $visibility = RecipeVisibility::Public): static
    {
        return $this->finalized($visibility)->afterCreating(function (Recipe $recipe): void {
            $revision = new RecipeDraftRevision;
            $revision->forceFill(['base_recipe_version_id' => $recipe->current_recipe_version_id]);
            $revision->recipe()->associate($recipe);
            $revision->save();
        });
    }

    public function publicFinalizedWithActiveRevision(): static
    {
        return $this->finalizedWithActiveRevision(RecipeVisibility::Public);
    }

    public function privateFinalizedWithActiveRevision(): static
    {
        return $this->finalizedWithActiveRevision(RecipeVisibility::Private);
    }

    public function withMultipleHistoricalVersions(): static
    {
        return $this->finalizedPublic()->afterCreating(function (Recipe $recipe): void {
            $version = RecipeVersion::factory()->for($recipe)->create([
                'version_number' => 2,
                'visibility' => $recipe->visibility,
            ]);
            $recipe->forceFill(['current_recipe_version_id' => $version->getKey()])->save();
        });
    }

    public function withDraftBasedOnPreviousVersion(): static
    {
        return $this->withMultipleHistoricalVersions()->afterCreating(function (Recipe $recipe): void {
            $base = $recipe->versions()->where('version_number', 1)->sole();
            $revision = new RecipeDraftRevision;
            $revision->forceFill(['base_recipe_version_id' => $base->getKey()]);
            $revision->recipe()->associate($recipe);
            $revision->save();
        });
    }

    private function finalized(RecipeVisibility $visibility): static
    {
        return $this->state(fn (): array => [
            'servings' => '2.00',
            'lifecycle' => RecipeLifecycle::Finalized,
            'visibility' => $visibility,
            'finalized_at' => now()->utc(),
        ])->afterCreating(function (Recipe $recipe) use ($visibility): void {
            $version = RecipeVersion::factory()->for($recipe)->create([
                'visibility' => $visibility,
                'public_attribution_name' => PublicProfile::query()
                    ->where('user_id', $recipe->user_id)
                    ->value('attribution_name'),
            ]);
            $recipe->forceFill(['current_recipe_version_id' => $version->getKey()])->save();
        });
    }
}
