<?php

namespace App\Models;

use App\Domain\MealPlans\MealPlanType;
use App\Domain\MealPlans\MealPlanVisibility;
use Database\Factories\MealPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
}
