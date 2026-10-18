<?php

namespace App\Domain\AccountExports;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Models\AccountExport;
use App\Models\User;

final class ExportAudit
{
    public function record(AccountExport $export, string $event, ?User $actor = null): void
    {
        $user = User::query()->find($export->user_id);
        app(AuditEventRecorder::class)->record(
            AuditAction::AccountExportEvent,
            $actor ? AuditActor::authenticatedUser($actor) : AuditActor::system(),
            $user ? AuditSubject::user($user) : AuditSubject::system(),
            ['event' => $event, 'outcome' => 'completed', 'export_id' => $export->getKey()],
            $export->correlation_id,
        );
    }
}
