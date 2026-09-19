<?php

namespace App\Models;

use App\Domain\Diary\DiaryEntryKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * @property DiaryEntryKind $kind
 * @property array<string, mixed> $source_snapshot
 */
class DiaryEntry extends Model
{
    use HasFactory;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Diary entry source snapshots are immutable.'));
    }

    protected function casts(): array
    {
        return ['kind' => DiaryEntryKind::class, 'source_version_number' => 'integer', 'source_snapshot' => 'array'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function consumptionState(): HasOne
    {
        return $this->hasOne(DiaryConsumptionState::class);
    }
}
