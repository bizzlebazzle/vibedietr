<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** @property array<string, mixed> $evidence */
class CatalogueModerationDecision extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['note', 'evidence', 'actor_identity_id'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Moderation decisions are append-only.'));
        static::deleting(fn () => throw new LogicException('Moderation decisions are append-only.'));
    }
}
