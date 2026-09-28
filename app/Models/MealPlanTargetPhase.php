<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 */
class MealPlanTargetPhase extends Model
{
    protected $fillable = ['nutrition_target_profile_id', 'profile_name_snapshot', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date'];
    }

    public function mealPlan(): BelongsTo
    {
        return $this->belongsTo(MealPlan::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(NutritionTargetProfile::class, 'nutrition_target_profile_id');
    }

    /** @return HasMany<MealPlanTargetPhaseValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(MealPlanTargetPhaseValue::class);
    }
}
