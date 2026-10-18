<?php

namespace App\Jobs;

use App\Domain\AccountDeletion\AccountLifecycle;
use App\Domain\AccountDeletion\PurgeAccount;
use App\Observability\CorrelationContext;
use App\Queue\CorrelationId;
use App\Queue\Exceptions\RetryableJobException;
use App\Queue\JobFailureReporter;
use App\Queue\QueueName;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class ProcessAccountDeletion implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const OPERATION_TYPE = 'account_deletion.process';

    public int $tries = 3;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 75;

    public readonly string $deletionId;

    public readonly string $correlationId;

    public function __construct(string $deletionId, ?string $correlationId = null)
    {
        if (! Str::isUlid($deletionId)) {
            throw new InvalidArgumentException('A valid deletion identifier is required.');
        }
        $this->deletionId = $deletionId;
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
        return [(new WithoutOverlapping('account-deletion:'.$this->deletionId))->shared()->releaseAfter(10)->expireAfter(75)];
    }

    public function uniqueId(): string
    {
        return $this->idempotencyFingerprint();
    }

    public function idempotencyFingerprint(): string
    {
        return hash('sha256', self::OPERATION_TYPE.'|'.$this->deletionId);
    }

    public function handle(PurgeAccount $service): void
    {
        app(CorrelationContext::class)->set($this->correlationId);

        try {
            app(AccountLifecycle::class)->apply($this->deletionId);
            $service->purge($this->deletionId);
        } catch (Throwable $exception) {
            throw RetryableJobException::fromUnexpected($exception, 'account_deletion_failed');
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(JobFailureReporter::class)->report(
            jobClass: self::class, operationType: self::OPERATION_TYPE,
            jobIdentifier: $this->job?->uuid(), idempotencyFingerprint: $this->idempotencyFingerprint(),
            correlationId: $this->correlationId, queue: $this->queue ?? QueueName::DEFAULT,
            attemptCount: $this->attempts(), exception: $exception ?? new RetryableJobException('account_deletion_failed'),
            resourceIdentifier: $this->deletionId,
        );
    }
}
