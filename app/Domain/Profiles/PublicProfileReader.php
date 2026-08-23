<?php

namespace App\Domain\Profiles;

use App\Domain\Recipes\PublicRecipeSummary;
use App\Models\PublicProfile;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class PublicProfileReader
{
    public function findEnabled(string $profileId): PublicProfilePage
    {
        $profile = PublicProfile::query()
            ->whereKey($profileId)
            ->where('profile_enabled', true)
            ->firstOrFail();
        $attributionName = $profile->attribution_name;

        if (! is_string($attributionName) || trim($attributionName) === '') {
            throw (new ModelNotFoundException)->setModel(PublicProfile::class, [$profileId]);
        }

        return new PublicProfilePage(
            id: (string) $profile->getKey(),
            attributionName: trim($attributionName),
            recipes: $profile->show_public_recipes
                ? $this->summariesFor($profile, remix: false)
                : [],
            remixes: $profile->show_public_remixes
                ? $this->summariesFor($profile, remix: true)
                : [],
            showsRecipes: $profile->show_public_recipes,
            showsRemixes: $profile->show_public_remixes,
        );
    }

    /** @return list<PublicRecipeSummary> */
    private function summariesFor(PublicProfile $profile, bool $remix): array
    {
        return Recipe::query()
            ->publiclyViewable()
            ->where('user_id', $profile->user_id)
            ->when(
                $remix,
                fn (Builder $query): Builder => $query->whereHas('remixLineage'),
                fn (Builder $query): Builder => $query->whereDoesntHave('remixLineage'),
            )
            ->with(['currentVersion', 'publicTags:id,recipe_id,name', 'managedTerms:id,category,name'])
            ->orderByDesc('finalized_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Recipe $recipe): PublicRecipeSummary => PublicRecipeSummary::fromCurrentVersion($recipe))
            ->all();
    }
}
