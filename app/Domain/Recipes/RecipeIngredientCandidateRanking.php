<?php

namespace App\Domain\Recipes;

final readonly class RecipeIngredientCandidateRanking
{
    /** @param list<RankedRecipeIngredientMatchCandidate> $candidates */
    public function __construct(
        public array $candidates,
        public ?RankedRecipeIngredientMatchCandidate $selectedCandidate,
        public RecipeIngredientCandidateSelectionOutcome $selectionOutcome,
    ) {}
}
