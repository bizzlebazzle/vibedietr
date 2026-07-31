<?php

namespace App\Models;

use App\Audit\AuditIntegrity;
use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditActorType;
use App\Audit\Enums\AuditPurpose;
use App\Audit\Enums\AuditRetentionClass;
use App\Audit\Enums\AuditSubjectType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditEvent extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit events are append-only and cannot be updated.'));
        static::deleting(fn () => throw new LogicException('Audit events are append-only and cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'purpose' => AuditPurpose::class,
            'retention_class' => AuditRetentionClass::class,
            'actor_type' => AuditActorType::class,
            'subject_type' => AuditSubjectType::class,
            'occurred_at' => 'immutable_datetime',
            'schema_version' => 'integer',
            'payload' => 'array',
        ];
    }

    public function hasValidIntegrityHash(): bool
    {
        return app(AuditIntegrity::class)->verify($this);
    }
}
