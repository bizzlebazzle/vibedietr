<?php

namespace App\Domain\Recipes;

use App\Models\ManagedRecipeTerm;
use App\Models\ManagedRecipeTermSuggestion;
use App\Models\PublicRecipeTag;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class PublicRecipeMetadataManager
{
    public function __construct(private readonly ManagedRecipeVocabularyAudit $audit) {}

    public function addFreeFormTag(int $recipeId, User $creator, string $name): PublicRecipeTag
    {
        return DB::transaction(function () use ($recipeId, $creator, $name): PublicRecipeTag {
            $recipe = $this->ownedRecipe($recipeId, $creator);
            $normalized = RecipeTagName::normalized($name);

            $existing = $recipe->publicTags()->where('normalized_name', $normalized)->first();
            if ($existing instanceof PublicRecipeTag) {
                return $existing;
            }

            $tag = new PublicRecipeTag;
            $tag->forceFill(['name' => RecipeTagName::display($name), 'normalized_name' => $normalized]);
            $tag->recipe()->associate($recipe);
            $tag->save();

            return $tag;
        });
    }

    public function removeFreeFormTag(int $recipeId, int $tagId, User $creator): void
    {
        DB::transaction(function () use ($recipeId, $tagId, $creator): void {
            $recipe = $this->ownedRecipe($recipeId, $creator);
            $tag = $recipe->publicTags()->findOrFail($tagId);
            $tag->delete();
        });
    }

    public function attachManagedTerm(int $recipeId, string $termId, User $creator): void
    {
        DB::transaction(function () use ($recipeId, $termId, $creator): void {
            $recipe = $this->ownedRecipe($recipeId, $creator);
            $term = ManagedRecipeTerm::query()->lockForUpdate()->findOrFail($termId);

            if (! $term->is_active) {
                throw ValidationException::withMessages(['managed_term_id' => 'That classification is inactive.']);
            }

            $recipe->managedTerms()->syncWithoutDetaching([$term->getKey()]);
        });
    }

    public function removeManagedTerm(int $recipeId, string $termId, User $creator): void
    {
        DB::transaction(function () use ($recipeId, $termId, $creator): void {
            $recipe = $this->ownedRecipe($recipeId, $creator);
            $recipe->managedTerms()->detach($termId);
        });
    }

    public function suggest(int $recipeId, string $termId, User $administrator): ManagedRecipeTermSuggestion
    {
        Gate::forUser($administrator)->authorize('access-admin');

        try {
            return DB::transaction(function () use ($recipeId, $termId, $administrator): ManagedRecipeTermSuggestion {
                $recipe = Recipe::query()->lockForUpdate()->findOrFail($recipeId);
                $term = ManagedRecipeTerm::query()->lockForUpdate()->findOrFail($termId);

                if (! $term->is_active) {
                    throw ValidationException::withMessages(['managed_term_id' => 'Inactive terms cannot be suggested.']);
                }

                if ($recipe->managedTerms()->whereKey($term->getKey())->exists()) {
                    throw ValidationException::withMessages(['managed_term_id' => 'The recipe already has that classification.']);
                }

                $pendingKey = $recipe->getKey().':'.$term->getKey().':'.ManagedRecipeTermSuggestionSource::Administrator->value;

                $suggestion = new ManagedRecipeTermSuggestion;
                $suggestion->forceFill([
                    'source' => ManagedRecipeTermSuggestionSource::Administrator,
                    'status' => ManagedRecipeTermSuggestionStatus::Pending,
                    'pending_key' => $pendingKey,
                ]);
                $suggestion->recipe()->associate($recipe);
                $suggestion->term()->associate($term);
                $suggestion->suggestedBy()->associate($administrator);
                $suggestion->save();

                return $suggestion;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['managed_term_id' => 'That suggestion is already pending.']);
        }
    }

    public function review(string $suggestionId, User $creator, ManagedRecipeTermSuggestionStatus $decision): ManagedRecipeTermSuggestion
    {
        if ($decision === ManagedRecipeTermSuggestionStatus::Pending) {
            throw ValidationException::withMessages(['decision' => 'Choose accept or reject.']);
        }

        return DB::transaction(function () use ($suggestionId, $creator, $decision): ManagedRecipeTermSuggestion {
            $suggestion = ManagedRecipeTermSuggestion::query()->lockForUpdate()->findOrFail($suggestionId);
            Gate::forUser($creator)->authorize('review', $suggestion);

            if ($suggestion->status === $decision) {
                return $suggestion;
            }

            if ($suggestion->status !== ManagedRecipeTermSuggestionStatus::Pending) {
                throw ValidationException::withMessages(['decision' => 'That suggestion has already been reviewed.']);
            }

            $recipe = Recipe::query()->lockForUpdate()->findOrFail($suggestion->recipe_id);

            if ((int) $recipe->user_id !== (int) $creator->getKey()) {
                throw new AuthorizationException;
            }

            if ($decision === ManagedRecipeTermSuggestionStatus::Accepted) {
                $term = ManagedRecipeTerm::query()->lockForUpdate()->findOrFail($suggestion->managed_recipe_term_id);
                if (! $term->is_active) {
                    throw ValidationException::withMessages(['decision' => 'That classification is no longer active.']);
                }
                $recipe->managedTerms()->syncWithoutDetaching([$term->getKey()]);
            }

            $suggestion->forceFill([
                'status' => $decision,
                'pending_key' => null,
                'decided_at' => now()->utc(),
            ])->save();
            $this->audit->suggestionReviewed($suggestion, $creator, $decision->value);

            return $suggestion;
        });
    }

    private function ownedRecipe(int $recipeId, User $creator): Recipe
    {
        $recipe = Recipe::query()->lockForUpdate()->findOrFail($recipeId);

        if ((int) $recipe->user_id !== (int) $creator->getKey()) {
            throw new AuthorizationException;
        }

        return $recipe;
    }
}
