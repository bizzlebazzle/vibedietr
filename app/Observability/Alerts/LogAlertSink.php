<?php

namespace App\Observability\Alerts;

use App\Observability\OperationalTelemetry;

final class LogAlertSink implements AlertSink
{
    public function __construct(private readonly OperationalTelemetry $telemetry) {}

    public function send(string $category, string $state, array $context = []): void
    {
        $this->telemetry->event('operational_alert', array_merge($context, [
            'alert_category' => $category,
            'state' => $state,
            'recipient_role' => (string) config('observability.alert_recipient_role', 'primary_administrator'),
        ]), $state === 'critical' ? 'critical' : 'warning');
    }
}
