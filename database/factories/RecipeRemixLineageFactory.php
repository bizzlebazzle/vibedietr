<?php

namespace Database\Factories;

use App\Domain\Recipes\RecipeLifecycle;
use App\Domain\Recipes\RecipeVisibility;
use App\Models\Recipe;
use App\Models\RecipeRemixLineage;
use App\Models\RecipeVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<RecipeRemixLineage> */
class RecipeRemixLineageFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $source = Recipe::factory()->finalizedPublic()->create();

        return [
            'remix_recipe_id' => Recipe::factory()->state([
                'lifecycle' => RecipeLifecycle::Draft,
                'visibility' => RecipeVisibility::Private,
            ]),
            'source_recipe_id' => $source->getKey(),
            'source_recipe_version_id' => $source->current_recipe_version_id,
            'source_version_number' => $source->currentVersion()->sole()->version_number,
            'source_creator_user_id' => $source->user_id,
            'operation_id' => (string) Str::ulid(),
        ];
    }

    public function fromPublicFinalizedSource(): static
    {
        return $this;
    }

    public function fromAccessiblePrivateFinalizedSource(): static
    {
        return $this->afterCreating(function (RecipeRemixLineage $lineage): void {
            $source = Recipe::query()->findOrFail($lineage->source_recipe_id);
            $source->forceFill(['visibility' => RecipeVisibility::Private])->save();
            $lineage->remixRecipe()->firstOrFail()->forceFill([
                'user_id' => $source->user_id,
            ])->save();
        });
    }

    public function whoseSourceIsPrivate(): static
    {
        return $this->afterCreating(function (RecipeRemixLineage $lineage): void {
            Recipe::query()->whereKey($lineage->source_recipe_id)->update([
                'visibility' => RecipeVisibility::Private,
            ]);
        });
    }

    public function whoseSourceIsDeleted(): static
    {
        return $this->afterCreating(function (RecipeRemixLineage $lineage): void {
            Recipe::query()->whereKey($lineage->source_recipe_id)->delete();
        });
    }

    public function whoseSourceCreatorIsDeleted(): static
    {
        return $this->afterCreating(function (RecipeRemixLineage $lineage): void {
            $source = Recipe::query()->find($lineage->source_recipe_id);
            $source?->owner()->first()?->delete();
        });
    }

    public function whoseSourceHasNewerFinalizedVersion(): static
    {
        return $this->afterCreating(function (RecipeRemixLineage $lineage): void {
            $source = Recipe::query()->findOrFail($lineage->source_recipe_id);
            $version = RecipeVersion::factory()->for($source)->create([
                'version_number' => $lineage->source_version_number + 1,
                'visibility' => $source->visibility,
            ]);
            $source->forceFill(['current_recipe_version_id' => $version->getKey()])->save();
        });
    }
}
