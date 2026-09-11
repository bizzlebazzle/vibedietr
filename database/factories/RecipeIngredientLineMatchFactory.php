<?php

namespace Database\Factories;

use App\Domain\Recipes\RecipeIngredientMatchConfidenceBand;
use App\Domain\Recipes\RecipeIngredientMatchProvenance;
use App\Domain\Recipes\RecipeIngredientMatchReviewState;
use App\Domain\Recipes\RecipeIngredientMatchThresholdPolicy;
use App\Models\CatalogueItemVersion;
use App\Models\Recipe;
use App\Models\RecipeIngredientLine;
use App\Models\RecipeIngredientLineMatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RecipeIngredientLineMatch> */
class RecipeIngredientLineMatchFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'recipe_ingredient_line_id' => RecipeIngredientLine::factory(),
            'catalogue_item_version_id' => CatalogueItemVersion::factory(),
            'candidate_score' => null,
            'confidence_band' => null,
            'threshold_version' => null,
            'selected_by_user_id' => User::factory(),
            'provenance' => RecipeIngredientMatchProvenance::ManuallySelectedByCreator,
            'review_state' => RecipeIngredientMatchReviewState::Confirmed,
        ];
    }

    public function needsReview(): static
    {
        return $this->state(fn (): array => [
            'candidate_score' => '0.950000000000000000',
            'confidence_band' => RecipeIngredientMatchConfidenceBand::Reviewable,
            'threshold_version' => RecipeIngredientMatchThresholdPolicy::VERSION,
            'selected_by_user_id' => null,
            'provenance' => RecipeIngredientMatchProvenance::AutomaticallySelected,
            'review_state' => RecipeIngredientMatchReviewState::NeedsReview,
        ]);
    }

    public function live(): static
    {
        return $this->for(
            RecipeIngredientLine::factory()->for(Recipe::factory(), 'recipe'),
            'ingredientLine',
        );
    }

    public function editableRevision(): static
    {
        return $this->for(
            RecipeIngredientLine::factory()->for(Recipe::factory()->finalizedWithActiveRevision(), 'recipe'),
            'ingredientLine',
        );
    }

    public function historical(): static
    {
        return $this->for(
            RecipeIngredientLine::factory()->for(Recipe::factory()->finalizedPublic(), 'recipe'),
            'ingredientLine',
        );
    }
}
