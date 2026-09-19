<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string|null $current_transition_id
 * @property int $next_sequence
 */
class DiaryConsumptionState extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['next_sequence' => 'integer'];
    }

    public function recipeEntry(): BelongsTo
    {
        return $this->belongsTo(MealPlanRecipeEntry::class, 'meal_plan_recipe_entry_id');
    }

    public function itemEntry(): BelongsTo
    {
        return $this->belongsTo(MealPlanItemEntry::class, 'meal_plan_item_entry_id');
    }

    public function diaryEntry(): BelongsTo
    {
        return $this->belongsTo(DiaryEntry::class);
    }

    /** @return HasMany<DiaryConsumptionTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(DiaryConsumptionTransition::class)->orderBy('sequence');
    }

    /** @return BelongsTo<DiaryConsumptionTransition, $this> */
    public function currentTransition(): BelongsTo
    {
        return $this->belongsTo(DiaryConsumptionTransition::class, 'current_transition_id');
    }
}
