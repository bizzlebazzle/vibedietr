<?php

namespace App\Administrator;

use App\Models\AdministratorLifecycleState;
use App\Models\AuditEvent;
use Illuminate\Support\Facades\Date;
use RuntimeException;

final class AdministratorBootstrapMarker
{
    private static int $depth = 0;

    public function complete(AdministratorLifecycleState $state, AuditEvent $event, string $correlationId): void
    {
        if ($state->bootstrap_completed_at !== null) {
            throw new RuntimeException('Administrator bootstrap has already completed.');
        }

        self::$depth++;
        try {
            $state->forceFill([
                'bootstrap_completed_at' => Date::now()->utc(),
                'bootstrap_audit_event_id' => $event->getKey(),
                'bootstrap_correlation_id' => $correlationId,
            ])->save();
        } finally {
            self::$depth--;
        }
    }

    public static function isAuthorized(): bool
    {
        return self::$depth > 0;
    }
}
