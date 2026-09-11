<?php

namespace App\Domain\Recipes;

final readonly class RankedRecipeIngredientMatchCandidate
{
    public function __construct(
        public int $catalogueItemId,
        public string $catalogueVersionId,
        public string $candidateScore,
        public ?AutomaticRecipeIngredientMatchEvidence $selectionEvidence,
    ) {}
}
