<?php

namespace App\Jobs;

use App\Domain\AccountExports\BuildAccountExport;
use App\Models\AccountExport;
use App\Observability\CorrelationContext;
use App\Queue\CorrelationId;
use App\Queue\Exceptions\NonRetryableJobException;
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

final class GenerateAccountExport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const OPERATION_TYPE = 'account_export.generate';

    public int $tries = 3;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 75;

    public readonly string $exportId;

    public readonly string $correlationId;

    public function __construct(string $exportId, ?string $correlationId = null)
    {
        if (! Str::isUlid($exportId)) {
            throw new InvalidArgumentException('A valid export identifier is required.');
        }
        $this->exportId = $exportId;
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
        return [(new WithoutOverlapping('account-export:'.$this->exportId))->shared()->releaseAfter(10)->expireAfter(75)];
    }

    public function uniqueId(): string
    {
        return $this->idempotencyFingerprint();
    }

    public function idempotencyFingerprint(): string
    {
        return hash('sha256', self::OPERATION_TYPE.'|'.$this->exportId);
    }

    public function handle(BuildAccountExport $service): void
    {
        app(CorrelationContext::class)->set($this->correlationId);

        try {
            $service->generate($this->exportId);
        } catch (NonRetryableJobException $exception) {
            AccountExport::query()->whereKey($this->exportId)->whereIn('state', ['queued', 'failed'])->update(['state' => 'failed']);
            $this->fail($exception);
        } catch (Throwable $exception) {
            throw RetryableJobException::fromUnexpected($exception, 'account_export_failed');
        }
    }

    public function failed(?Throwable $exception): void
    {
        AccountExport::query()->whereKey($this->exportId)->whereIn('state', ['queued', 'failed'])->update(['state' => 'failed']);
        app(JobFailureReporter::class)->report(
            jobClass: self::class, operationType: self::OPERATION_TYPE,
            jobIdentifier: $this->job?->uuid(), idempotencyFingerprint: $this->idempotencyFingerprint(),
            correlationId: $this->correlationId, queue: $this->queue ?? QueueName::DEFAULT,
            attemptCount: $this->attempts(), exception: $exception ?? new RetryableJobException('account_export_failed'),
            resourceIdentifier: $this->exportId,
        );
    }
}
