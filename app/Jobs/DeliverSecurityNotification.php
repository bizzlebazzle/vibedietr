<?php

namespace App\Jobs;

use App\Configuration\ProductionConfigurationValidator;
use App\Models\SecurityNotificationHealth;
use App\Models\SecurityNotificationIntent;
use App\Models\User;
use App\Observability\OperationalTelemetry;
use App\Queue\Exceptions\NonRetryableJobException;
use App\Queue\Exceptions\RetryableJobException;
use App\Queue\JobFailureReporter;
use App\Queue\QueueName;
use App\Security\Notifications\SecurityNotificationTransport;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Date;
use Throwable;

final class DeliverSecurityNotification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 86400;

    public function __construct(public readonly string $intentId)
    {
        $this->onQueue(QueueName::SECURITY_NOTIFICATIONS);
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [10, 60];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->intentId))->releaseAfter(10)->expireAfter(45)];
    }

    public function uniqueId(): string
    {
        return $this->intentId;
    }

    public function handle(SecurityNotificationTransport $transport): void
    {
        $startedAt = microtime(true);
        app(ProductionConfigurationValidator::class)->assertReady();

        $intent = SecurityNotificationIntent::query()->find($this->intentId);

        if ($intent === null || $intent->status === 'provider_accepted') {
            return;
        }

        $recipient = $intent->recipient;

        if (! $recipient instanceof User) {
            $this->permanentlyReject($intent, 'security_recipient_missing');

            $this->fail(new NonRetryableJobException('security_recipient_missing'));

            return;
        }

        $intent->update(['status' => 'processing']);

        try {
            $providerReference = $transport->send($recipient, $intent);
            $intent->update(['status' => 'provider_accepted', 'provider_reference' => $providerReference, 'provider_accepted_at' => Date::now(), 'failure_code' => null]);
            app(OperationalTelemetry::class)->timing('provider.request', (microtime(true) - $startedAt) * 1000, [
                'provider' => 'security_mail',
                'correlation_id' => $intent->correlation_id,
                'operation' => 'security_notification.deliver',
                'outcome' => 'success',
            ]);
        } catch (NonRetryableJobException $exception) {
            $this->permanentlyReject($intent, $exception->safeErrorCode);
            $this->recordProviderFailure($intent, $startedAt, 'permanent_failure');
            $this->fail($exception);
        } catch (RetryableJobException $exception) {
            $intent->update(['status' => 'deferred', 'failure_code' => $exception->safeErrorCode]);
            $this->recordProviderFailure($intent, $startedAt, 'retryable_failure');
            throw $exception;
        } catch (Throwable $exception) {
            $intent->update(['status' => 'deferred', 'failure_code' => 'security_delivery_unexpected']);
            $this->recordProviderFailure($intent, $startedAt, 'unexpected_failure');
            throw RetryableJobException::fromUnexpected($exception, 'security_delivery_unexpected');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $intent = SecurityNotificationIntent::query()->find($this->intentId);

        if ($intent !== null && $intent->status !== 'permanently_rejected') {
            $intent->update(['status' => 'retry_exhausted', 'failure_code' => 'security_delivery_retry_exhausted', 'terminal_at' => Date::now()]);
            $this->markUnhealthy('security_delivery_retry_exhausted');
        }

        $exception ??= new NonRetryableJobException('security_delivery_failed');
        app(JobFailureReporter::class)->report(
            self::class,
            'security_notification.deliver',
            $this->job?->uuid(),
            hash('sha256', $this->intentId),
            $intent === null ? 'unavailable' : $intent->correlation_id,
            $this->queue ?? QueueName::SECURITY_NOTIFICATIONS,
            $this->attempts(),
            $exception,
        );
    }

    private function permanentlyReject(SecurityNotificationIntent $intent, string $code): void
    {
        $intent->update(['status' => 'permanently_rejected', 'failure_code' => $code, 'terminal_at' => Date::now()]);
        $this->markUnhealthy($code);
    }

    private function markUnhealthy(string $code): void
    {
        SecurityNotificationHealth::query()->updateOrCreate(['id' => 1], ['channel_healthy' => false, 'last_failure_code' => $code]);
    }

    private function recordProviderFailure(SecurityNotificationIntent $intent, float $startedAt, string $outcome): void
    {
        $telemetry = app(OperationalTelemetry::class);
        $labels = [
            'provider' => 'security_mail',
            'correlation_id' => $intent->correlation_id,
            'operation' => 'security_notification.deliver',
            'outcome' => $outcome,
        ];
        $telemetry->timing('provider.request', (microtime(true) - $startedAt) * 1000, $labels);
        $telemetry->counter('provider.failure', $labels);
    }
}
