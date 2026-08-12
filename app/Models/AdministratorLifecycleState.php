<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class AdministratorLifecycleState extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['bootstrap_completed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::creating(fn () => throw new LogicException('The administrator lifecycle state is migration-owned.'));
        self::deleting(fn () => throw new LogicException('The administrator lifecycle state cannot be deleted.'));
    }
}
