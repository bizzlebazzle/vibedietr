<?php

namespace App\Security;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Models\AuditEvent;
use App\Models\User;

final class SecurityAuditService
{
    public function __construct(private readonly AuditEventRecorder $recorder) {}

    public function factor(User $actor, User $subject, string $event, string $outcome, string $operation, string $correlationId, ?string $reasonCode = null): AuditEvent
    {
        return $this->recorder->record(
            AuditAction::SecuritySecondFactorEvent,
            $actor->isAdministrator() ? AuditActor::administrator($actor) : AuditActor::authenticatedUser($actor),
            AuditSubject::user($subject),
            array_filter(['event' => $event, 'outcome' => $outcome, 'operation' => $operation, 'reason_code' => $reasonCode], fn ($value) => $value !== null),
            correlationId: $correlationId,
        );
    }

    public function factorSystem(User $subject, string $event, string $outcome, string $operation, string $correlationId, ?string $reasonCode = null): AuditEvent
    {
        return $this->recorder->record(
            AuditAction::SecuritySecondFactorEvent,
            AuditActor::system(),
            AuditSubject::user($subject),
            array_filter(['event' => $event, 'outcome' => $outcome, 'operation' => $operation, 'reason_code' => $reasonCode], fn ($value) => $value !== null),
            correlationId: $correlationId,
        );
    }

    public function notification(User $subject, string $event, string $outcome, string $recipientCategory, string $deliveryStatus, string $correlationId): AuditEvent
    {
        return $this->recorder->record(
            AuditAction::SecurityNotificationEvent,
            AuditActor::system(),
            AuditSubject::user($subject),
            ['event' => $event, 'outcome' => $outcome, 'recipient_category' => $recipientCategory, 'delivery_status' => $deliveryStatus],
            correlationId: $correlationId,
        );
    }
}
