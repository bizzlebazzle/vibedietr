<?php

namespace App\Models;

use Database\Factories\MealPlanBookmarkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MealPlanBookmark extends Model
{
    /** @use HasFactory<MealPlanBookmarkFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function mealPlan(): BelongsTo
    {
        return $this->belongsTo(MealPlan::class);
    }
}
