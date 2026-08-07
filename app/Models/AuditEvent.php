<?php

namespace App\Models;

use App\Audit\AuditIntegrity;
use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditActorType;
use App\Audit\Enums\AuditPurpose;
use App\Audit\Enums\AuditRetentionClass;
use App\Audit\Enums\AuditSubjectType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property string $id
 * @property AuditAction $action
 * @property AuditPurpose $purpose
 * @property AuditRetentionClass $retention_class
 * @property AuditActorType $actor_type
 * @property string|null $actor_identity_id
 * @property AuditSubjectType $subject_type
 * @property string|null $subject_identity_id
 * @property string|null $subject_identifier
 * @property CarbonImmutable $occurred_at
 * @property string|null $correlation_id
 * @property string|null $evidence_reference
 * @property int $schema_version
 * @property array<string, mixed> $payload
 * @property string $integrity_hash
 */
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
