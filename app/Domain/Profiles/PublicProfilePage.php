<?php

namespace App\Domain\Profiles;

use App\Domain\Recipes\PublicRecipeSummary;

final readonly class PublicProfilePage
{
    /**
     * @param  list<PublicRecipeSummary>  $recipes
     * @param  list<PublicRecipeSummary>  $remixes
     */
    public function __construct(
        public string $id,
        public string $attributionName,
        public array $recipes,
        public array $remixes,
        public bool $showsRecipes,
        public bool $showsRemixes,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'attribution_name' => $this->attributionName,
            'recipes' => array_map(
                fn (PublicRecipeSummary $recipe): array => $recipe->toArray(),
                $this->recipes,
            ),
            'remixes' => array_map(
                fn (PublicRecipeSummary $recipe): array => $recipe->toArray(),
                $this->remixes,
            ),
        ];
    }
}
