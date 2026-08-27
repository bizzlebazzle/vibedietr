<?php

namespace App\Queue;

use App\Observability\Monitoring\OperationalState;
use App\Observability\OperationalTelemetry;
use App\Queue\Exceptions\JobOperationException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Throwable;

final class JobFailureReporter
{
    public function report(
        string $jobClass,
        string $operationType,
        ?string $jobIdentifier,
        string $idempotencyFingerprint,
        string $correlationId,
        string $queue,
        int $attemptCount,
        Throwable $exception,
    ): void {
        [$failureCategory, $safeErrorCode] = $this->classify($exception);
        app(OperationalTelemetry::class)->counter('queue.final_failure', [
            'job_type' => $jobClass,
            'queue' => $queue,
            'outcome' => 'failed',
            'failure_category' => $failureCategory,
        ]);
        if ($jobIdentifier !== null && $attemptCount > 1) {
            app(OperationalState::class)->recordReplayFailure($jobIdentifier);
        }

        Log::error('queued_job_failed', [
            'job_class' => $jobClass,
            'operation_type' => $operationType,
            'job_identifier' => $jobIdentifier ?? 'unavailable',
            'idempotency_fingerprint' => $idempotencyFingerprint,
            'correlation_id' => $correlationId,
            'failure_category' => $failureCategory,
            'exception_class' => $exception::class,
            'safe_error_code' => $safeErrorCode,
            'attempt_count' => $attemptCount,
            'queue' => $queue,
            'failed_at' => Date::now()->utc()->toIso8601String(),
        ]);
    }

    /** @return array{string, string} */
    private function classify(Throwable $exception): array
    {
        return match (true) {
            $exception instanceof JobOperationException => [
                $exception->failureCategory,
                $exception->safeErrorCode,
            ],
            $exception instanceof TimeoutExceededException => ['timeout', 'job_timeout'],
            $exception instanceof MaxAttemptsExceededException => ['retry_exhausted', 'job_max_attempts'],
            default => ['unexpected', 'unexpected_exception'],
        };
    }
}
