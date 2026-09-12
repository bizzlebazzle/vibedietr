<?php

namespace App\Models;

use App\Domain\MealPlans\MealPlanType;
use App\Domain\MealPlans\MealPlanVisibility;
use Database\Factories\MealPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property MealPlanType $type
 * @property MealPlanVisibility $visibility
 */
class MealPlan extends Model
{
    /** @use HasFactory<MealPlanFactory> */
    use HasFactory;

    protected $fillable = ['name', 'type', 'starts_on', 'ends_on'];

    protected static function booted(): void
    {
        static::updating(function (MealPlan $mealPlan): void {
            if (! $mealPlan->isDirty(['type', 'starts_on', 'ends_on']) || ! $mealPlan->days()->exists()) {
                return;
            }

            $hasIncompatibleDay = $mealPlan->type === MealPlanType::Reusable
                ? $mealPlan->days()->whereNotNull('date')->exists()
                : $mealPlan->days()->where(function ($query) use ($mealPlan): void {
                    $query->whereNotNull('day_index')
                        ->orWhereDate('date', '<', $mealPlan->starts_on)
                        ->orWhereDate('date', '>', $mealPlan->ends_on);
                })->exists();

            if ($hasIncompatibleDay) {
                throw ValidationException::withMessages([
                    'type' => 'The plan type or date range is incompatible with its existing days.',
                ]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'type' => MealPlanType::class,
            'visibility' => MealPlanVisibility::class,
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<MealPlanDay, $this> */
    public function days(): HasMany
    {
        return $this->hasMany(MealPlanDay::class)
            ->orderByRaw('COALESCE(day_index, 2147483647)')
            ->orderBy('date');
    }
}
