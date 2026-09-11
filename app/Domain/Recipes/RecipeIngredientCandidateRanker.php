<?php

namespace App\Domain\Recipes;

use App\Domain\Catalogue\CatalogueReadQuery;
use App\Models\User;
use Illuminate\Support\Collection;

final class RecipeIngredientCandidateRanker
{
    public function __construct(
        private readonly CatalogueReadQuery $catalogue,
        private readonly RecipeIngredientMatchThresholdPolicy $thresholds,
    ) {}

    /** @param iterable<RecipeIngredientMatchCandidateScore> $candidates */
    public function rank(User $actor, iterable $candidates, bool $lock = false): RecipeIngredientCandidateRanking
    {
        $candidates = Collection::make($candidates)->values();

        $eligible = $this->catalogue
            ->eligibleAutomaticItems($actor, $candidates->pluck('catalogueItemId')->all(), $lock)
            ->keyBy(fn ($item): int => (int) $item->getKey());

        $ranked = $candidates
            ->filter(function (RecipeIngredientMatchCandidateScore $candidate) use ($eligible): bool {
                $item = $eligible->get($candidate->catalogueItemId);

                return $item !== null
                    && $item->current_catalogue_item_version_id === $candidate->catalogueVersionId;
            })
            ->map(function (RecipeIngredientMatchCandidateScore $candidate): RankedRecipeIngredientMatchCandidate {
                $score = $this->thresholds->normalize($candidate->candidateScore);

                return new RankedRecipeIngredientMatchCandidate(
                    catalogueItemId: $candidate->catalogueItemId,
                    catalogueVersionId: $candidate->catalogueVersionId,
                    candidateScore: $score,
                    selectionEvidence: $this->thresholds->evaluate($score),
                );
            })
            ->sort(function (RankedRecipeIngredientMatchCandidate $left, RankedRecipeIngredientMatchCandidate $right): int {
                $scoreOrder = $this->thresholds->compare($right->candidateScore, $left->candidateScore);

                return $scoreOrder !== 0
                    ? $scoreOrder
                    : (($left->catalogueItemId <=> $right->catalogueItemId)
                        ?: strcmp($left->catalogueVersionId, $right->catalogueVersionId));
            })
            ->unique('catalogueItemId')
            ->values()
            ->all();

        if ($ranked === []) {
            return new RecipeIngredientCandidateRanking(
                [],
                null,
                RecipeIngredientCandidateSelectionOutcome::NoEligibleCandidates,
            );
        }

        $top = $ranked[0];
        if ($top->selectionEvidence === null) {
            return new RecipeIngredientCandidateRanking(
                $ranked,
                null,
                RecipeIngredientCandidateSelectionOutcome::BelowThreshold,
            );
        }

        if (isset($ranked[1]) && $this->thresholds->compare($top->candidateScore, $ranked[1]->candidateScore) === 0) {
            return new RecipeIngredientCandidateRanking(
                $ranked,
                null,
                RecipeIngredientCandidateSelectionOutcome::TopScoreTie,
            );
        }

        return new RecipeIngredientCandidateRanking(
            $ranked,
            $top,
            RecipeIngredientCandidateSelectionOutcome::Selected,
        );
    }
}
