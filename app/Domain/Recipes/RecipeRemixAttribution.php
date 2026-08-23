<?php

namespace App\Domain\Recipes;

final readonly class RecipeRemixAttribution
{
    public function __construct(
        public int $versionNumber,
        public bool $sourceAvailable,
        public ?int $sourceRecipeId = null,
        public ?string $sourceTitle = null,
    ) {}
}
