<?php

namespace App\Security\Notifications;

use App\Configuration\ProductionConfigurationValidator;
use App\Models\SecurityNotificationHealth;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use RuntimeException;

final class ProductionSecurityReadiness
{
    public function __construct(private readonly ProductionConfigurationValidator $configuration) {}

    /** @return list<string> */
    public function failures(): array
    {
        if (! app()->environment('production')) {
            return [];
        }

        $failures = $this->configuration->failures();
        $mailer = (string) config('administrator-security.notifications.mailer');
        $transport = (string) config("mail.mailers.$mailer.transport");

        if (! in_array($transport, ['resend', 'smtp', 'ses', 'ses-v2', 'postmark'], true)) {
            $failures[] = 'A permitted production transactional mail transport is required.';
        }

        if (in_array($mailer, ['log', 'array'], true) || in_array($transport, ['log', 'array', 'null', 'sendmail', 'failover', 'roundrobin'], true)) {
            $failures[] = 'Local, fake, null, sendmail, and failover transports cannot provide administrator security delivery.';
        }

        if (config('administrator-security.notifications.provider') === null) {
            $failures[] = 'The administrator security notification provider must be identified.';
        }

        if (! config('administrator-security.notifications.sender_verified')) {
            $failures[] = 'The production sender identity must be verified.';
        }

        if (! config('session.secure')) {
            $failures[] = 'Secure session cookies are required.';
        }

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $failures[] = 'HTTPS is required.';
        }

        if (! is_string(config('app.key')) || config('app.key') === '') {
            $failures[] = 'A valid application encryption key is required.';
        }

        if (in_array(config('queue.default'), ['sync', 'null'], true)) {
            $failures[] = 'A durable asynchronous queue connection is required.';
        }

        if (User::query()->where('is_administrator', true)->whereNull('email_verified_at')->exists()) {
            $failures[] = 'Every active administrator requires a verified notification destination.';
        }

        if ($transport === 'smtp') {
            $smtp = config('mail.mailers.'.$mailer);
            $host = $smtp['host'] ?? null;
            if (! in_array($smtp['scheme'] ?? null, ['tls', 'smtps'], true)
                || ! is_string($host) || in_array($host, ['127.0.0.1', 'localhost', 'mailpit', 'mailhog'], true)
                || empty($smtp['username']) || empty($smtp['password'])) {
                $failures[] = 'SMTP must use authenticated encrypted submission to a non-local relay.';
            }
        }

        if ($transport === 'resend' && empty(config('services.resend.key'))) {
            $failures[] = 'Resend credentials are required.';
        }

        if ($transport === 'postmark' && empty(config('services.postmark.token'))) {
            $failures[] = 'Postmark credentials are required.';
        }

        $health = SecurityNotificationHealth::query()->find(1);
        $now = Date::now();

        if ($health === null || ! $health->channel_healthy) {
            $failures[] = 'The security notification channel is unhealthy.';
        }

        if ($health === null || ! $health->clock_synchronized) {
            $failures[] = 'Trusted synchronized clock health is required.';
        }

        if ($health === null || ! $health->audit_persistence_healthy) {
            $failures[] = 'Required security audit persistence must be healthy.';
        }

        if ($health === null || ! $health->capacity_available || $health->capacity_checked_at === null || Date::parse($health->capacity_checked_at)->lt($now->copy()->subSeconds((int) config('administrator-security.notifications.capacity_health_ttl_seconds')))) {
            $failures[] = 'Recent provider capacity headroom evidence is required.';
        }

        if ($health === null || $health->provider_accepted_at === null || Date::parse($health->provider_accepted_at)->lt($now->copy()->subSeconds((int) config('administrator-security.notifications.provider_health_ttl_seconds')))) {
            $failures[] = 'A recent controlled provider acceptance check is required.';
        }

        $monitorSince = $now->copy()->subSeconds((int) config('administrator-security.notifications.monitor_health_ttl_seconds'));

        if ($health === null || $health->worker_heartbeat_at === null || Date::parse($health->worker_heartbeat_at)->lt($monitorSince) || $health->failure_monitor_heartbeat_at === null || Date::parse($health->failure_monitor_heartbeat_at)->lt($monitorSince)) {
            $failures[] = 'Security queue workers and failed-job monitoring must be healthy.';
        }

        return $failures;
    }

    public function assertReady(): void
    {
        $failures = $this->failures();

        if ($failures !== []) {
            throw new RuntimeException('Administrator security is not production-ready: '.implode(' ', $failures));
        }
    }
}
