<?php

namespace Tests\Feature;

use App\Jobs\DeliverSecurityNotification;
use App\Models\SecurityNotificationHealth;
use App\Models\SecurityNotificationIntent;
use App\Models\User;
use App\Security\Notifications\ProductionSecurityReadiness;
use App\Security\Notifications\SecurityEventNotification;
use App\Security\Notifications\SecurityEventType;
use App\Security\Notifications\SecurityNotificationIntentService;
use App\Security\SecurityAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdministratorSecurityNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-08-12 12:00:00 UTC');
    }

    public function test_every_approved_event_has_a_notification_definition(): void
    {
        $this->assertCount(24, SecurityEventType::cases());
        foreach (SecurityEventType::cases() as $event) {
            $mail = (new SecurityEventNotification($event, '01k2-correlation', 'testing', 'ci'))->toMail(new \stdClass);
            $content = json_encode($mail->toArray());
            $this->assertStringContainsString($event->label(), $content);
            $this->assertStringContainsString('01k2-correlation', $content);
            $this->assertStringNotContainsString('password', strtolower($content));
            $this->assertStringNotContainsString('recovery code:', strtolower($content));
        }
    }

    public function test_intents_select_exact_recipients_propagate_correlation_and_suppress_duplicates(): void
    {
        Queue::fake();
        $affected = User::factory()->administrator()->create(['email_verified_at' => now()]);
        $other = User::factory()->administrator()->create(['email_verified_at' => now()]);
        User::factory()->create(['email_verified_at' => now()]);
        $service = app(SecurityNotificationIntentService::class);

        $first = $service->create(SecurityEventType::RecoveryCodeUsed, $affected, '01k2-safe-correlation');
        $second = $service->create(SecurityEventType::RecoveryCodeUsed, $affected, '01k2-safe-correlation');

        $this->assertCount(2, $first);
        $this->assertCount(2, $second);
        $this->assertDatabaseCount('security_notification_intents', 2);
        $this->assertEqualsCanonicalizing([$affected->id, $other->id], SecurityNotificationIntent::pluck('recipient_user_id')->all());
        $this->assertTrue(SecurityNotificationIntent::query()->where('correlation_id', '01k2-safe-correlation')->where('status', 'queued')->count() === 2);
        Queue::assertPushed(DeliverSecurityNotification::class, 2);
    }

    public function test_non_administrator_enrollment_notifies_only_that_account(): void
    {
        Queue::fake();
        $affected = User::factory()->create(['email_verified_at' => now()]);
        User::factory()->administrator()->create(['email_verified_at' => now()]);

        $intents = app(SecurityNotificationIntentService::class)->create(SecurityEventType::FactorEnrollmentCompleted, $affected, '01k2-enrollment');
        $this->assertCount(1, $intents);
        $this->assertSame('affected_account', $intents[0]->recipient_category);
    }

    public function test_provider_acceptance_is_not_recorded_as_delivery_or_read(): void
    {
        $intent = new SecurityNotificationIntent(['status' => 'provider_accepted']);
        $this->assertSame('provider_accepted', $intent->status);
        $this->assertNotSame('delivered', $intent->status);
        $this->assertNotSame('read', $intent->status);
    }

    public function test_production_rejects_local_fake_null_and_incomplete_configuration_without_echoing_secrets(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config([
            'app.env' => 'production',
            'app.url' => 'http://example.test',
            'session.secure' => false,
            'administrator-security.notifications.mailer' => 'log',
            'administrator-security.notifications.provider' => null,
            'administrator-security.notifications.sender_verified' => false,
        ]);

        $failures = app(ProductionSecurityReadiness::class)->failures();
        $this->assertNotEmpty($failures);
        $this->assertStringContainsString('Local', implode(' ', $failures));
        $this->assertStringNotContainsString((string) config('app.key'), implode(' ', $failures));
    }

    public function test_complete_recent_production_health_can_satisfy_readiness(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'session.driver' => 'database',
            'production.trusted_hosts' => ['app.example.com'],
            'production.trusted_proxies_setting' => 'none',
            'production.trusted_proxies' => [],
            'production.trusted_proxy_headers_setting' => 'x-forwarded-for,x-forwarded-host,x-forwarded-port,x-forwarded-proto',
            'cache.default' => 'database',
            'production.storage.durable_disk' => 's3',
            'filesystems.default' => 's3',
            'filesystems.disks.s3.key' => 'synthetic-key',
            'filesystems.disks.s3.secret' => 'synthetic-secret',
            'filesystems.disks.s3.region' => 'eu-west-2',
            'filesystems.disks.s3.bucket' => 'synthetic-bucket',
            'administrator-security.verification.source_fingerprint_key' => 'synthetic-fingerprint',
            'services.openfoodfacts.user_agent' => 'VibeDietr/1.0 (https://app.example.com/contact)',
            'app.url' => 'https://app.example.com',
            'session.secure' => true,
            'queue.default' => 'database',
            'administrator-security.notifications.mailer' => 'smtp',
            'administrator-security.notifications.provider' => 'qualifying-smtp',
            'administrator-security.notifications.sender_verified' => true,
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.scheme' => 'tls',
            'mail.mailers.smtp.host' => 'smtp.example.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => 'safe-user',
            'mail.mailers.smtp.password' => 'test-only-value',
        ]);
        SecurityNotificationHealth::query()->create([
            'id' => 1,
            'channel_healthy' => true,
            'capacity_available' => true,
            'clock_synchronized' => true,
            'audit_persistence_healthy' => true,
            'provider_accepted_at' => now(),
            'capacity_checked_at' => now(),
            'worker_heartbeat_at' => now(),
            'failure_monitor_heartbeat_at' => now(),
        ]);

        $this->assertSame([], app(ProductionSecurityReadiness::class)->failures());
    }

    public function test_security_audit_is_correlated_and_secret_free(): void
    {
        $user = User::factory()->create();
        $event = app(SecurityAuditService::class)->factor($user, $user, 'enrollment_confirmed', 'completed', 'enrollment', '01k2-audit-correlation');

        $this->assertSame('01k2-audit-correlation', $event->correlation_id);
        $encoded = json_encode($event->payload);
        $this->assertStringNotContainsString('secret', strtolower($encoded));
        $this->assertStringNotContainsString('code', strtolower($encoded));
    }
}
