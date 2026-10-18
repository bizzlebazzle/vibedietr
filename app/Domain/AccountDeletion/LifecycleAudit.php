<?php

namespace App\Domain\AccountDeletion;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Models\User;

final class LifecycleAudit
{
    public function record(User $user, string $event, string $correlation): void
    {
        app(AuditEventRecorder::class)->record(
            AuditAction::AccountLifecycleEvent,
            AuditActor::system(), AuditSubject::user($user),
            ['event' => $event, 'outcome' => 'completed'], $correlation,
        );
    }
}
