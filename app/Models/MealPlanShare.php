<?php

namespace App\Models;

use Database\Factories\MealPlanShareFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MealPlanShare extends Model
{
    /** @use HasFactory<MealPlanShareFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['private_recipe_snapshots_acknowledged_at' => 'immutable_datetime'];
    }

    public function mealPlan(): BelongsTo
    {
        return $this->belongsTo(MealPlan::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
