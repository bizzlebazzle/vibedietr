<?php

namespace App\Models;

use App\Domain\MealPlans\PlanSlotKey;
use Database\Factories\MealPlanSlotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property PlanSlotKey|null $standard_key
 * @property string $name
 * @property int $position
 */
class MealPlanSlot extends Model
{
    /** @use HasFactory<MealPlanSlotFactory> */
    use HasFactory;

    protected $fillable = ['name'];

    protected function casts(): array
    {
        return ['standard_key' => PlanSlotKey::class];
    }

    /** @return BelongsTo<MealPlanDay, $this> */
    public function day(): BelongsTo
    {
        return $this->belongsTo(MealPlanDay::class, 'meal_plan_day_id');
    }

    /** @return HasMany<MealPlanRecipeEntry, $this> */
    public function recipeEntries(): HasMany
    {
        return $this->hasMany(MealPlanRecipeEntry::class)->orderBy('id');
    }
}
