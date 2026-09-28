<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property array<string, array<string, string|null>> $targets */
class MealPlanTargetPhaseValue extends Model
{
    protected $fillable = ['effective_on', 'targets'];

    protected function casts(): array
    {
        return ['effective_on' => 'immutable_date', 'targets' => 'array'];
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(MealPlanTargetPhase::class, 'meal_plan_target_phase_id');
    }
}
