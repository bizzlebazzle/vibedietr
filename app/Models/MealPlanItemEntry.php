<?php

namespace App\Models;

use App\Domain\MealPlans\MealPlanItemEntryKind;
use App\Domain\Measurements\StandardUnit;
use Database\Factories\MealPlanItemEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * @property MealPlanItemEntryKind $kind
 * @property string $planned_amount
 * @property StandardUnit $planned_unit
 * @property array<string, mixed>|null $catalogue_snapshot
 * @property list<array<string, mixed>>|null $catalogue_nutrition_snapshot
 * @property string|null $one_off_wording
 * @property array<string, mixed>|null $one_off_nutrition
 */
class MealPlanItemEntry extends Model
{
    /** @use HasFactory<MealPlanItemEntryFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (self $entry): void {
            if ($entry->isDirty([
                'kind',
                'planned_amount',
                'planned_unit',
                'catalogue_item_id',
                'catalogue_item_version_id',
                'catalogue_item_version_number',
                'catalogue_snapshot',
                'catalogue_nutrition_snapshot',
                'one_off_wording',
                'one_off_nutrition',
            ])) {
                throw new LogicException('Pinned meal-plan item data is immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'kind' => MealPlanItemEntryKind::class,
            'planned_amount' => 'decimal:18',
            'planned_unit' => StandardUnit::class,
            'catalogue_item_version_number' => 'integer',
            'catalogue_snapshot' => 'array',
            'catalogue_nutrition_snapshot' => 'array',
            'one_off_nutrition' => 'array',
        ];
    }

    /** @return BelongsTo<MealPlanSlot, $this> */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(MealPlanSlot::class, 'meal_plan_slot_id');
    }

    public function consumptionState(): HasOne
    {
        return $this->hasOne(DiaryConsumptionState::class, 'meal_plan_item_entry_id');
    }
}
