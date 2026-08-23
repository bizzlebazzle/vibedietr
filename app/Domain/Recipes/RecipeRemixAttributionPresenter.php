<?php

namespace App\Domain\Recipes;

use App\Domain\Profiles\PublicAttribution;
use App\Models\Recipe;
use App\Models\RecipeRemixLineage;
use App\Models\RecipeVersion;
use App\Models\User;

final class RecipeRemixAttributionPresenter
{
    public function present(RecipeRemixLineage $lineage, ?User $viewer): RecipeRemixAttribution
    {
        $source = Recipe::query()
            ->visibleTo($viewer)
            ->whereKey($lineage->source_recipe_id)
            ->first();

        if (! $source instanceof Recipe) {
            return new RecipeRemixAttribution($lineage->source_version_number, false);
        }

        $version = RecipeVersion::query()
            ->whereKey($lineage->source_recipe_version_id)
            ->where('recipe_id', $source->getKey())
            ->first();

        if (! $version instanceof RecipeVersion) {
            return new RecipeRemixAttribution($lineage->source_version_number, false);
        }

        return new RecipeRemixAttribution(
            versionNumber: $lineage->source_version_number,
            sourceAvailable: true,
            sourceRecipeId: (int) $source->getKey(),
            sourceTitle: (string) ($version->snapshot['title'] ?? ''),
            sourceAttribution: PublicAttribution::fromVersion($source, $version),
        );
    }
}
