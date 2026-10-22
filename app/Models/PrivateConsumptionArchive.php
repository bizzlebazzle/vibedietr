<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Owner-only DEC-022 history; never a restorable meal plan.
 *
 * @property array<string, mixed> $history
 */
class PrivateConsumptionArchive extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    protected $hidden = ['history', 'user_id', 'source_plan_generation'];

    protected function casts(): array
    {
        return ['history' => 'encrypted:array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Archived consumption history is immutable.'));
    }
}
