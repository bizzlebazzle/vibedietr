<?php

namespace App\Models;

use App\Domain\NutritionTargets\NutritionTargetType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NutritionTarget extends Model
{
    protected $fillable = ['nutrient', 'type', 'exact_value', 'minimum_value', 'maximum_value'];

    protected function casts(): array
    {
        return [
            'type' => NutritionTargetType::class,
            'exact_value' => 'decimal:18',
            'minimum_value' => 'decimal:18',
            'maximum_value' => 'decimal:18',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(NutritionTargetProfile::class, 'nutrition_target_profile_id');
    }
}
