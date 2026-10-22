<?php

namespace App\Jobs;

use App\Domain\AccountDeletion\PruneAuditRetention;
use App\Observability\Alerts\AlertSink;
use App\Observability\CorrelationContext;
use App\Queue\CorrelationId;
use App\Queue\Exceptions\RetryableJobException;
use App\Queue\JobFailureReporter;
use App\Queue\QueueName;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

final class PruneAccountRetention implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const OPERATION_TYPE = 'account_retention.prune';

    public int $tries = 3;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 75;

    public readonly string $correlationId;

    public function __construct(?string $correlationId = null)
    {
        $this->correlationId = CorrelationId::resolve($correlationId);
        $this->onQueue(QueueName::DEFAULT);
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [10, 60];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('account-retention'))->shared()->releaseAfter(10)->expireAfter(75)];
    }

    public function uniqueId(): string
    {
        return $this->idempotencyFingerprint();
    }

    public function idempotencyFingerprint(): string
    {
        return hash('sha256', self::OPERATION_TYPE);
    }

    public function handle(PruneAuditRetention $service): void
    {
        app(CorrelationContext::class)->set($this->correlationId);

        try {
            $result = $service->run();
            if ($result['missing_clocks'] > 0) {
                app(AlertSink::class)->send('audit_retention_evidence', 'critical', ['count' => $result['missing_clocks']]);
            }
        } catch (Throwable $exception) {
            throw RetryableJobException::fromUnexpected($exception, 'account_retention_failed');
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(JobFailureReporter::class)->report(
            jobClass: self::class, operationType: self::OPERATION_TYPE,
            jobIdentifier: $this->job?->uuid(), idempotencyFingerprint: $this->idempotencyFingerprint(),
            correlationId: $this->correlationId, queue: $this->queue ?? QueueName::DEFAULT,
            attemptCount: $this->attempts(), exception: $exception ?? new RetryableJobException('account_retention_failed'),
        );
    }
}
