<?php

namespace App\Jobs;

use App\Audit\AuditReferenceValidator;
use App\Observability\CorrelationContext;
use App\Observability\OperationalTelemetry;
use App\Queue\CorrelationId;
use App\Queue\Exceptions\NonRetryableJobException;
use App\Queue\Exceptions\RetryableJobException;
use App\Queue\JobFailureReporter;
use App\Queue\QueueName;
use App\Queue\Reference\ReferenceTaskOutcome;
use App\Queue\Reference\ReferenceTaskResultRecorder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use InvalidArgumentException;
use Throwable;

final class ProcessReferenceTask implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const IDEMPOTENCY_LIFETIME_SECONDS = 86_400;

    public const OPERATION_TYPE = 'reference_task.process';

    public int $tries = 3;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public int $uniqueFor = self::IDEMPOTENCY_LIFETIME_SECONDS;

    public readonly string $operationReference;

    public readonly ?string $targetReference;

    public readonly string $correlationId;

    public function __construct(
        string $operationReference,
        ?string $targetReference = null,
        ?string $correlationId = null,
    ) {
        $this->operationReference = AuditReferenceValidator::validate(
            $operationReference,
            'operation reference',
        ) ?? throw new InvalidArgumentException('An operation reference is required.');
        $this->targetReference = AuditReferenceValidator::validate(
            $targetReference,
            'target reference',
        );
        $this->correlationId = $correlationId === null && app()->bound(CorrelationContext::class)
            ? app(CorrelationContext::class)->get()
            : CorrelationId::resolve($correlationId);

        $this->onQueue(QueueName::DEFAULT);
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->idempotencyFingerprint()))
                ->releaseAfter(10)
                ->expireAfter(75),
        ];
    }

    public function uniqueId(): string
    {
        return $this->idempotencyFingerprint();
    }

    public function idempotencyFingerprint(): string
    {
        return hash('sha256', self::OPERATION_TYPE.'|'.$this->operationReference);
    }

    public function handle(ReferenceTaskResultRecorder $resultRecorder): void
    {
        $startedAt = microtime(true);
        app(OperationalTelemetry::class)->event('queued_job_started', [
            'correlation_id' => $this->correlationId,
            'operation' => self::OPERATION_TYPE,
            'job_type' => self::class,
            'queue' => $this->queue ?? QueueName::DEFAULT,
            'attempt_count' => $this->attempts(),
        ]);
        try {
            $resultRecorder->recordOnce(
                $this->idempotencyFingerprint(),
                $this->correlationId,
                $this->targetReference === null
                    ? ReferenceTaskOutcome::SkippedMissingTarget
                    : ReferenceTaskOutcome::Completed,
                self::IDEMPOTENCY_LIFETIME_SECONDS,
            );
        } catch (NonRetryableJobException $exception) {
            app(OperationalTelemetry::class)->timing('critical_workflow.reference_task', (microtime(true) - $startedAt) * 1000, [
                'correlation_id' => $this->correlationId,
                'operation' => self::OPERATION_TYPE,
                'job_type' => self::class,
                'queue' => $this->queue ?? QueueName::DEFAULT,
                'outcome' => 'completed',
            ]);
            $this->fail($exception);
        } catch (RetryableJobException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw RetryableJobException::fromUnexpected(
                $exception,
                'reference_task_unexpected',
            );
        }
    }

    public function failed(?Throwable $exception): void
    {
        $exception ??= new NonRetryableJobException('reference_task_failed');
        $jobIdentifier = $this->job !== null
            ? $this->job->uuid()
            : null;

        app(JobFailureReporter::class)->report(
            jobClass: self::class,
            operationType: self::OPERATION_TYPE,
            jobIdentifier: $jobIdentifier,
            idempotencyFingerprint: $this->idempotencyFingerprint(),
            correlationId: $this->correlationId,
            queue: $this->queue ?? 'default',
            attemptCount: $this->attempts(),
            exception: $exception,
        );
    }
}
