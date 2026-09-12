<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MealPlanDayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int|null $day_index
 * @property CarbonImmutable|null $date
 */
class MealPlanDay extends Model
{
    /** @use HasFactory<MealPlanDayFactory> */
    use HasFactory;

    protected $fillable = ['day_index', 'date'];

    protected function casts(): array
    {
        return ['date' => 'immutable_date'];
    }

    /** @return BelongsTo<MealPlan, $this> */
    public function mealPlan(): BelongsTo
    {
        return $this->belongsTo(MealPlan::class);
    }

    /** @return HasMany<MealPlanSlot, $this> */
    public function slots(): HasMany
    {
        return $this->hasMany(MealPlanSlot::class)->orderBy('position');
    }
}
