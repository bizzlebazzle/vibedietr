<?php

namespace App\Models;

use App\Audit\Enums\AuditIdentityType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AuditActorIdentity extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit identity mappings cannot be updated.'));
        static::deleting(fn () => throw new LogicException(
            'Audit identity mappings may only be erased through AuditActorIdentityEraser.'
        ));
    }

    protected function casts(): array
    {
        return [
            'identity_type' => AuditIdentityType::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
