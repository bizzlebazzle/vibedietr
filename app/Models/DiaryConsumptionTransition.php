<?php

namespace App\Models;

use App\Domain\Diary\ConsumptionAction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $id
 * @property int $sequence
 * @property ConsumptionAction $action
 * @property string|null $predecessor_id
 * @property string|null $target_transition_id
 * @property CarbonImmutable $recorded_at
 * @property string|null $actual_amount
 * @property string|null $actual_unit
 * @property CarbonImmutable|null $consumed_local_at
 * @property string|null $timezone
 * @property int|null $utc_offset_minutes
 * @property CarbonImmutable|null $consumed_at_utc
 * @property CarbonImmutable|null $effective_diary_date
 * @property string|null $consumption_snapshot_id
 */
class DiaryConsumptionTransition extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Consumption history is immutable.'));
        static::deleting(fn () => throw new LogicException('Consumption history cannot be deleted directly.'));
    }

    protected function casts(): array
    {
        return [
            'sequence' => 'integer', 'action' => ConsumptionAction::class,
            'recorded_at' => 'immutable_datetime', 'actual_amount' => 'decimal:18',
            'utc_offset_minutes' => 'integer', 'consumed_local_at' => 'immutable_datetime',
            'consumed_at_utc' => 'immutable_datetime', 'effective_diary_date' => 'immutable_date',
        ];
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(DiaryConsumptionState::class, 'diary_consumption_state_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
