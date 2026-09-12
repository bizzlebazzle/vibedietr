<?php

namespace App\Models;

use Database\Factories\MealPlanRecipeEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $planned_servings
 * @property array<string, mixed> $recipe_snapshot
 * @property array<string, mixed> $nutrition_snapshot
 */
class MealPlanRecipeEntry extends Model
{
    /** @use HasFactory<MealPlanRecipeEntryFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (MealPlanRecipeEntry $entry): void {
            if ($entry->isDirty([
                'recipe_id',
                'recipe_version_id',
                'recipe_version_number',
                'recipe_snapshot',
                'nutrition_snapshot',
            ])) {
                throw new LogicException('Pinned meal-plan recipe snapshots are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'recipe_version_number' => 'integer',
            'planned_servings' => 'decimal:2',
            'recipe_snapshot' => 'array',
            'nutrition_snapshot' => 'array',
        ];
    }

    /** @return BelongsTo<MealPlanSlot, $this> */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(MealPlanSlot::class, 'meal_plan_slot_id');
    }
}
