<?php

namespace App\Domain\Recipes;

final readonly class RecipeIngredientMatchCandidateScore
{
    public function __construct(
        public int $catalogueItemId,
        public string $catalogueVersionId,
        public string|int $candidateScore,
    ) {}
}
