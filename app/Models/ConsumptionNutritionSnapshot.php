<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $id
 * @property string $source_entry_type
 * @property int $source_entry_id
 * @property string $item_kind
 * @property int|null $source_id
 * @property string|null $source_version_id
 * @property int|null $source_version_number
 * @property string|null $nutrition_source
 * @property bool $is_estimate
 * @property string $actual_amount
 * @property string $actual_unit
 * @property array<string, mixed>|list<array<string, mixed>>|null $nutrition
 */
class ConsumptionNutritionSnapshot extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Consumption nutrition snapshots are immutable.'));
        static::deleting(fn () => throw new LogicException('Consumption nutrition snapshots cannot be deleted directly.'));
    }

    protected function casts(): array
    {
        return [
            'source_entry_id' => 'integer',
            'source_id' => 'integer',
            'source_version_number' => 'integer',
            'is_estimate' => 'boolean',
            'actual_amount' => 'decimal:18',
            'nutrition' => 'array',
        ];
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(DiaryConsumptionState::class, 'diary_consumption_state_id');
    }
}
