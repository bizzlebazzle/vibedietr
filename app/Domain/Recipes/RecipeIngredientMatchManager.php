<?php

namespace App\Domain\Recipes;

use App\Domain\Catalogue\CatalogueCanonicalResolver;
use App\Domain\Catalogue\CatalogueItemStatus;
use App\Domain\Catalogue\CatalogueReadQuery;
use App\Models\CatalogueItem;
use App\Models\Recipe;
use App\Models\RecipeIngredientLineMatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RecipeIngredientMatchManager
{
    public function __construct(
        private readonly CatalogueReadQuery $catalogue,
        private readonly RecipeIngredientMatchThresholdPolicy $thresholds,
        private readonly RecipeIngredientCandidateRanker $ranker,
    ) {}

    /** @param iterable<RecipeIngredientMatchCandidateScore> $candidates */
    public function selectHighestRankedAutomatically(
        int $recipeId,
        int $lineId,
        iterable $candidates,
        User $actor,
    ): ?RecipeIngredientLineMatch {
        $candidates = is_array($candidates) ? array_values($candidates) : iterator_to_array($candidates, false);

        return DB::transaction(function () use ($recipeId, $lineId, $candidates, $actor): ?RecipeIngredientLineMatch {
            $recipe = Recipe::query()->lockForUpdate()->findOrFail($recipeId);
            Gate::forUser($actor)->authorize('update', $recipe);
            $recipe->ingredientLines()->lockForUpdate()->findOrFail($lineId);
            $ranking = $this->ranker->rank($actor, $candidates, lock: true);
            $candidate = $ranking->selectedCandidate;

            if ($candidate === null) {
                return null;
            }

            return $this->selectAutomatically(
                $recipeId,
                $lineId,
                $candidate->catalogueItemId,
                $candidate->catalogueVersionId,
                $candidate->candidateScore,
                $actor,
            );
        }, 3);
    }

    public function select(int $recipeId, int $lineId, int $catalogueItemId, string $catalogueVersionId, User $actor): RecipeIngredientLineMatch
    {
        return DB::transaction(function () use ($recipeId, $lineId, $catalogueItemId, $catalogueVersionId, $actor): RecipeIngredientLineMatch {
            $recipe = Recipe::query()->lockForUpdate()->findOrFail($recipeId);
            Gate::forUser($actor)->authorize('update', $recipe);
            $line = $recipe->ingredientLines()->lockForUpdate()->findOrFail($lineId);
            $version = $this->catalogue->findSelectableCurrentVersion($actor, $catalogueItemId, $catalogueVersionId, lock: true);

            if ($version === null) {
                throw ValidationException::withMessages([
                    'catalogue_match' => 'That catalogue result is no longer selectable. Search again before matching.',
                ]);
            }

            $match = $line->catalogueMatch()->lockForUpdate()->first() ?? new RecipeIngredientLineMatch;
            $match->forceFill([
                'catalogue_item_version_id' => $version->getKey(),
                'candidate_score' => null,
                'confidence_band' => null,
                'threshold_version' => null,
                'selected_by_user_id' => $actor->getKey(),
                'provenance' => RecipeIngredientMatchProvenance::ManuallySelectedByCreator,
                'review_state' => RecipeIngredientMatchReviewState::Confirmed,
            ]);
            $match->ingredientLine()->associate($line);
            $match->save();

            return $match->fresh(['catalogueItemVersion.catalogueItem']);
        }, 3);
    }

    public function selectAutomatically(
        int $recipeId,
        int $lineId,
        int $catalogueItemId,
        string $catalogueVersionId,
        string|int $candidateScore,
        User $actor,
    ): ?RecipeIngredientLineMatch {
        return DB::transaction(function () use ($recipeId, $lineId, $catalogueItemId, $catalogueVersionId, $candidateScore, $actor): ?RecipeIngredientLineMatch {
            $recipe = Recipe::query()->lockForUpdate()->findOrFail($recipeId);
            Gate::forUser($actor)->authorize('update', $recipe);
            $line = $recipe->ingredientLines()->lockForUpdate()->findOrFail($lineId);
            $evidence = $this->thresholds->evaluate($candidateScore);

            if ($evidence === null) {
                return null;
            }

            $version = $this->catalogue->findSelectableCurrentVersion(
                $actor,
                $catalogueItemId,
                $catalogueVersionId,
                lock: true,
            );

            if ($version === null
                || $version->catalogueItem()->where('status', CatalogueItemStatus::Approved)->doesntExist()) {
                throw ValidationException::withMessages([
                    'catalogue_match' => 'That catalogue result is not eligible for automatic matching.',
                ]);
            }

            $match = $line->catalogueMatch()->lockForUpdate()->first() ?? new RecipeIngredientLineMatch;
            $match->forceFill([
                'catalogue_item_version_id' => $version->getKey(),
                'candidate_score' => $evidence->candidateScore,
                'confidence_band' => $evidence->confidenceBand,
                'threshold_version' => $evidence->thresholdVersion,
                'selected_by_user_id' => null,
                'provenance' => RecipeIngredientMatchProvenance::AutomaticallySelected,
                'review_state' => $evidence->reviewState,
            ]);
            $match->ingredientLine()->associate($line);
            $match->save();

            return $match->fresh(['catalogueItemVersion.catalogueItem']);
        }, 3);
    }

    public function clear(int $recipeId, int $lineId, User $actor): void
    {
        DB::transaction(function () use ($recipeId, $lineId, $actor): void {
            $recipe = Recipe::query()->lockForUpdate()->findOrFail($recipeId);
            Gate::forUser($actor)->authorize('update', $recipe);
            $line = $recipe->ingredientLines()->lockForUpdate()->findOrFail($lineId);
            $line->catalogueMatch()->lockForUpdate()->delete();
        }, 3);
    }

    public function confirmRejectedReplacement(
        int $recipeId,
        int $lineId,
        User $actor,
    ): RecipeIngredientLineMatch {
        return DB::transaction(function () use ($recipeId, $lineId, $actor): RecipeIngredientLineMatch {
            $recipe = Recipe::query()->lockForUpdate()->findOrFail($recipeId);
            Gate::forUser($actor)->authorize('update', $recipe);
            $line = $recipe->ingredientLines()->lockForUpdate()->findOrFail($lineId);
            $match = $line->catalogueMatch()->lockForUpdate()->first();

            if (! $match instanceof RecipeIngredientLineMatch) {
                throw ValidationException::withMessages([
                    'catalogue_match' => 'This ingredient no longer has a catalogue match.',
                ]);
            }

            $sourceVersion = $match->catalogueItemVersion()->firstOrFail();
            $source = CatalogueItem::query()->lockForUpdate()->findOrFail($sourceVersion->catalogue_item_id);

            if ($source->status !== CatalogueItemStatus::Rejected
                || $source->suggested_replacement_catalogue_item_id === null) {
                throw ValidationException::withMessages([
                    'catalogue_match' => 'That unavailable match has no current approved replacement to confirm.',
                ]);
            }

            $target = CatalogueItem::query()
                ->whereKey($source->suggested_replacement_catalogue_item_id)
                ->lockForUpdate()
                ->first();
            $target = $target === null ? null : app(CatalogueCanonicalResolver::class)->resolve($target, lock: true);
            $targetVersionId = $target?->current_catalogue_item_version_id;

            if ($target === null
                || $target->status !== CatalogueItemStatus::Approved
                || $targetVersionId === null) {
                throw ValidationException::withMessages([
                    'catalogue_match' => 'The suggested replacement is no longer selectable.',
                ]);
            }

            $targetVersion = $this->catalogue->findSelectableCurrentVersion(
                $actor,
                (int) $target->getKey(),
                $targetVersionId,
                lock: true,
            );

            if ($targetVersion === null) {
                throw ValidationException::withMessages([
                    'catalogue_match' => 'The suggested replacement is no longer selectable.',
                ]);
            }

            $match->forceFill([
                'catalogue_item_version_id' => $targetVersion->getKey(),
                'candidate_score' => null,
                'confidence_band' => null,
                'threshold_version' => null,
                'selected_by_user_id' => $actor->getKey(),
                'provenance' => RecipeIngredientMatchProvenance::OwnerConfirmedReplacement,
                'review_state' => RecipeIngredientMatchReviewState::Confirmed,
            ])->save();

            return $match->fresh(['catalogueItemVersion.catalogueItem']);
        }, 3);
    }
}
