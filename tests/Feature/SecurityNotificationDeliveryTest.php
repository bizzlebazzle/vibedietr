<?php

namespace Tests\Feature;

use App\Jobs\DeliverSecurityNotification;
use App\Models\SecurityNotificationIntent;
use App\Models\User;
use App\Queue\Exceptions\NonRetryableJobException;
use App\Queue\Exceptions\RetryableJobException;
use App\Security\Notifications\SecurityNotificationTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityNotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_has_bounded_retry_timeout_backoff_and_identifier_only_payload(): void
    {
        $job = new DeliverSecurityNotification('01k2-safe-intent');
        $serialized = serialize($job);

        $this->assertSame(3, $job->tries);
        $this->assertSame(30, $job->timeout);
        $this->assertSame([10, 60], $job->backoff());
        $this->assertStringContainsString('01k2-safe-intent', $serialized);
        $this->assertStringNotContainsString('@example.test', $serialized);
    }

    public function test_provider_acceptance_is_persisted_once_and_duplicate_execution_is_safe(): void
    {
        $intent = $this->intent();
        $transport = new class implements SecurityNotificationTransport
        {
            public int $calls = 0;

            public function send(User $recipient, SecurityNotificationIntent $intent): string
            {
                $this->calls++;

                return 'provider-message-reference';
            }
        };
        $job = new DeliverSecurityNotification($intent->id);
        $job->handle($transport);
        $job->handle($transport);

        $this->assertSame(1, $transport->calls);
        $this->assertSame('provider_accepted', $intent->fresh()->status);
        $this->assertSame('provider-message-reference', $intent->fresh()->provider_reference);
    }

    public function test_retryable_failure_is_deferred_and_rethrown_without_private_context(): void
    {
        $intent = $this->intent();
        $transport = new class implements SecurityNotificationTransport
        {
            public function send(User $recipient, SecurityNotificationIntent $intent): string
            {
                throw new RetryableJobException('provider_timeout');
            }
        };

        try {
            (new DeliverSecurityNotification($intent->id))->handle($transport);
            $this->fail('Retryable failure was swallowed.');
        } catch (RetryableJobException $exception) {
            $this->assertSame('provider_timeout', $exception->safeErrorCode);
        }

        $this->assertSame('deferred', $intent->fresh()->status);
        $this->assertSame('provider_timeout', $intent->fresh()->failure_code);
    }

    public function test_permanent_failure_stops_delivery_and_marks_channel_unhealthy(): void
    {
        $intent = $this->intent();
        $transport = new class implements SecurityNotificationTransport
        {
            public function send(User $recipient, SecurityNotificationIntent $intent): string
            {
                throw new NonRetryableJobException('destination_unverified');
            }
        };

        (new DeliverSecurityNotification($intent->id))->handle($transport);
        $this->assertSame('permanently_rejected', $intent->fresh()->status);
        $this->assertDatabaseHas('security_notification_health', ['id' => 1, 'channel_healthy' => false, 'last_failure_code' => 'destination_unverified']);
    }

    private function intent(): SecurityNotificationIntent
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        return SecurityNotificationIntent::query()->create([
            'recipient_user_id' => $user->id,
            'event_type' => 'second_factor.enrollment_completed',
            'recipient_category' => 'affected_account',
            'destination_version' => hash('sha256', 'destination'),
            'idempotency_key' => hash('sha256', fake()->uuid()),
            'correlation_id' => '01k2-delivery-correlation',
            'environment' => 'testing',
            'application_instance' => 'ci',
            'status' => 'queued',
        ]);
    }
}
