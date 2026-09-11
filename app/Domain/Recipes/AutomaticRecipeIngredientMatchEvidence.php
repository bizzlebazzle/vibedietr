<?php

namespace App\Domain\Recipes;

final readonly class AutomaticRecipeIngredientMatchEvidence
{
    public function __construct(
        public string $candidateScore,
        public RecipeIngredientMatchConfidenceBand $confidenceBand,
        public int $thresholdVersion,
        public RecipeIngredientMatchReviewState $reviewState,
    ) {}
}
