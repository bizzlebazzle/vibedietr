<?php

namespace App\Domain\Recipes;

use App\Domain\Profiles\PublicAttribution;

final readonly class RecipeRemixAttribution
{
    public function __construct(
        public int $versionNumber,
        public bool $sourceAvailable,
        public ?int $sourceRecipeId = null,
        public ?string $sourceTitle = null,
        public ?PublicAttribution $sourceAttribution = null,
    ) {}
}
