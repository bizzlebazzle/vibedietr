<?php

namespace App\Models;

use App\Domain\MealPlans\MealPlanRecipeVersionReviewStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $meal_plan_recipe_entry_id
 * @property string $recipe_version_id
 * @property string $correlation_id
 * @property MealPlanRecipeVersionReviewStatus $status
 * @property MealPlanRecipeEntry $entry
 * @property RecipeVersion $recipeVersion
 */
class MealPlanRecipeVersionReview extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['status' => MealPlanRecipeVersionReviewStatus::class, 'resolved_at' => 'immutable_datetime'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(MealPlanRecipeEntry::class, 'meal_plan_recipe_entry_id');
    }

    public function recipeVersion(): BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class);
    }
}
