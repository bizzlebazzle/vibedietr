<?php

namespace App\Administrator;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Models\AuditEvent;
use App\Models\User;

final class AdministratorLifecycleAudit
{
    public function __construct(private readonly AuditEventRecorder $recorder) {}

    public function user(User $actor, User $subject, string $event, string $outcome, string $previous, string $resulting, string $correlationId, ?string $reason = null): AuditEvent
    {
        $auditActor = $actor->isAdministrator()
            ? AuditActor::administrator($actor)
            : AuditActor::authenticatedUser($actor);

        return $this->record($auditActor, AuditSubject::user($subject), $event, $outcome, $previous, $resulting, $correlationId, $reason);
    }

    public function external(string $operatorReference, User $subject, string $event, string $outcome, string $previous, string $resulting, string $correlationId, ?string $reason = null): AuditEvent
    {
        return $this->record(AuditActor::externalOperator($operatorReference), AuditSubject::user($subject), $event, $outcome, $previous, $resulting, $correlationId, $reason);
    }

    public function system(User $subject, string $event, string $outcome, string $previous, string $resulting, string $correlationId, ?string $reason = null): AuditEvent
    {
        return $this->record(AuditActor::system(), AuditSubject::user($subject), $event, $outcome, $previous, $resulting, $correlationId, $reason);
    }

    public function externalSystem(string $operatorReference, string $event, string $outcome, string $previous, string $resulting, string $correlationId, ?string $reason = null): AuditEvent
    {
        return $this->record(AuditActor::externalOperator($operatorReference), AuditSubject::system(), $event, $outcome, $previous, $resulting, $correlationId, $reason);
    }

    public function systemOperation(string $event, string $outcome, string $previous, string $resulting, string $correlationId, ?string $reason = null): AuditEvent
    {
        return $this->record(AuditActor::system(), AuditSubject::system(), $event, $outcome, $previous, $resulting, $correlationId, $reason);
    }

    private function record(AuditActor $actor, AuditSubject $subject, string $event, string $outcome, string $previous, string $resulting, string $correlationId, ?string $reason): AuditEvent
    {
        return $this->recorder->record(
            AuditAction::AdministratorLifecycleEvent,
            $actor,
            $subject,
            array_filter([
                'event' => $event,
                'outcome' => $outcome,
                'previous_privilege_state' => $previous,
                'resulting_privilege_state' => $resulting,
                'reason_code' => $reason,
            ], fn ($value) => $value !== null),
            correlationId: $correlationId,
        );
    }
}
