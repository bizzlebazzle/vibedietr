<?php

namespace Tests\Feature;

use App\Jobs\ProcessReferenceTask;
use App\Models\AuditEvent;
use App\Queue\Exceptions\NonRetryableJobException;
use App\Queue\Exceptions\RetryableJobException;
use App\Queue\Reference\CacheReferenceTaskResultRecorder;
use App\Queue\Reference\ReferenceTaskOutcome;
use App\Queue\Reference\ReferenceTaskResultRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Tests\TestCase;

class QueuedJobConventionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_job_can_be_dispatched(): void
    {
        Queue::fake();

        ProcessReferenceTask::dispatch(
            'reference-operation:dispatch',
            'reference-target:dispatch',
            '01K1CORRELATIONDISPATCH00000',
        );

        Queue::assertPushed(ProcessReferenceTask::class, function (ProcessReferenceTask $job): bool {
            return $job->queue === 'default'
                && $job->afterCommit === true
                && $job->operationReference === 'reference-operation:dispatch';
        });
    }

    public function test_duplicate_logical_dispatch_is_suppressed_while_the_first_job_is_pending(): void
    {
        Queue::fake();

        ProcessReferenceTask::dispatch('reference-operation:duplicate', 'reference-target:one');
        ProcessReferenceTask::dispatch('reference-operation:duplicate', 'reference-target:one');

        Queue::assertPushed(ProcessReferenceTask::class, 1);
    }

    public function test_reference_job_executes_and_records_one_safe_result(): void
    {
        $job = new ProcessReferenceTask(
            'reference-operation:execute',
            'reference-target:execute',
            '01K1CORRELATIONEXECUTE000000',
        );

        app()->call([$job, 'handle']);

        $this->assertSame([
            'correlation_id' => '01K1CORRELATIONEXECUTE000000',
            'outcome' => ReferenceTaskOutcome::Completed->value,
        ], Cache::get(CacheReferenceTaskResultRecorder::cacheKey($job->idempotencyFingerprint())));
    }

    public function test_rerunning_the_same_idempotency_key_does_not_duplicate_the_effect(): void
    {
        $job = new ProcessReferenceTask('reference-operation:rerun', 'reference-target:rerun');
        $recorder = new CountingReferenceTaskResultRecorder(
            app(CacheReferenceTaskResultRecorder::class),
        );

        $job->handle($recorder);
        $job->handle($recorder);

        $this->assertSame(2, $recorder->attempts);
        $this->assertSame(1, $recorder->effects);
    }

    public function test_retry_after_partial_success_does_not_duplicate_the_effect(): void
    {
        $job = new ProcessReferenceTask('reference-operation:partial', 'reference-target:partial');
        $recorder = new PartialSuccessReferenceTaskResultRecorder(
            app(CacheReferenceTaskResultRecorder::class),
        );

        try {
            $job->handle($recorder);
            $this->fail('The first attempt should simulate a transient failure.');
        } catch (RetryableJobException $exception) {
            $this->assertSame('reference_partial_failure', $exception->safeErrorCode);
        }

        $job->handle($recorder);

        $this->assertSame(2, $recorder->attempts);
        $this->assertSame(1, $recorder->effects);
    }

    public function test_correlation_id_is_preserved_and_missing_correlation_is_generated(): void
    {
        $preserved = new ProcessReferenceTask(
            'reference-operation:correlated',
            correlationId: 'parent:01K1CORRELATION',
        );
        $generated = new ProcessReferenceTask('reference-operation:generated');

        $this->assertSame('parent:01K1CORRELATION', $preserved->correlationId);
        $this->assertTrue(Str::isUlid($generated->correlationId));
    }

    #[DataProvider('invalidSafeReferences')]
    public function test_caller_supplied_correlation_and_operation_references_reject_unsafe_values(
        string $field,
        string $value,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        if ($field === 'correlation') {
            new ProcessReferenceTask('reference-operation:invalid-correlation', correlationId: $value);

            return;
        }

        new ProcessReferenceTask($value);
    }

    public static function invalidSafeReferences(): array
    {
        return [
            'correlation with spaces' => ['correlation', 'private request body'],
            'correlation raw IP address' => ['correlation', '203.0.113.10'],
            'correlation oversized' => ['correlation', str_repeat('a', 65)],
            'operation email address' => ['operation', 'person@example.test'],
        ];
    }

    public function test_retry_backoff_timeout_and_uniqueness_metadata_are_bounded(): void
    {
        $job = new ProcessReferenceTask('reference-operation:metadata');

        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 60], $job->backoff());
        $this->assertSame(60, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame(ProcessReferenceTask::IDEMPOTENCY_LIFETIME_SECONDS, $job->uniqueFor);
        $this->assertSame($job->idempotencyFingerprint(), $job->uniqueId());
        $this->assertLessThan((int) config('queue.connections.database.retry_after'), 75);
    }

    public function test_non_retryable_failure_is_failed_immediately(): void
    {
        $job = (new ProcessReferenceTask('reference-operation:permanent'))
            ->withFakeQueueInteractions();

        $job->handle(new PermanentFailureReferenceTaskResultRecorder);

        $job->assertFailedWith(NonRetryableJobException::class);
    }

    public function test_retry_exhaustion_reports_safe_context_and_persists_only_sanitized_failure_data(): void
    {
        $sensitiveExceptionInput = 'password=correct-horse-battery-staple';
        $sensitiveLookingJobInput = 'private-reference:do-not-log';
        $job = new ProcessReferenceTask(
            'reference-operation:exhaustion',
            $sensitiveLookingJobInput,
            '01K1CORRELATIONEXHAUSTION000',
        );

        app()->instance(
            ReferenceTaskResultRecorder::class,
            new UnexpectedFailureReferenceTaskResultRecorder($sensitiveExceptionInput),
        );
        $log = new RecordingLogger;
        Log::swap($log);
        Queue::connection('database')->push($job);
        DB::table('jobs')->update(['attempts' => 2]);

        $this->artisan('queue:work', [
            'connection' => 'database',
            '--queue' => 'default',
            '--once' => true,
            '--sleep' => 0,
            '--tries' => 3,
        ])->assertSuccessful();

        $failedJob = DB::table('failed_jobs')->sole();

        $this->assertStringNotContainsString($sensitiveExceptionInput, $failedJob->payload);
        $this->assertStringNotContainsString($sensitiveExceptionInput, $failedJob->exception);
        $this->assertStringContainsString(RetryableJobException::class, $failedJob->exception);
        $this->assertSame(0, AuditEvent::query()->count());

        $failureLogs = array_values(array_filter(
            $log->records,
            fn (array $record): bool => $record['message'] === 'queued_job_failed',
        ));
        $this->assertCount(1, $failureLogs);
        $context = $failureLogs[0]['context'];

        $this->assertSame(ProcessReferenceTask::class, $context['job_class']);
        $this->assertSame(ProcessReferenceTask::OPERATION_TYPE, $context['operation_type']);
        $this->assertNotSame('unavailable', $context['job_identifier']);
        $this->assertSame($job->idempotencyFingerprint(), $context['idempotency_fingerprint']);
        $this->assertSame('01K1CORRELATIONEXHAUSTION000', $context['correlation_id']);
        $this->assertSame('transient', $context['failure_category']);
        $this->assertSame(RetryableJobException::class, $context['exception_class']);
        $this->assertSame('reference_task_unexpected', $context['safe_error_code']);
        $this->assertSame(3, $context['attempt_count']);
        $this->assertSame('default', $context['queue']);
        $this->assertArrayHasKey('failed_at', $context);
        $encodedContext = json_encode($context, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($sensitiveExceptionInput, $encodedContext);
        $this->assertStringNotContainsString($sensitiveLookingJobInput, $encodedContext);
    }

    public function test_missing_target_is_handled_as_successfully_obsolete(): void
    {
        $job = new ProcessReferenceTask(
            'reference-operation:missing-target',
            correlationId: '01K1CORRELATIONMISSING00000',
        );

        app()->call([$job, 'handle']);

        $this->assertSame([
            'correlation_id' => '01K1CORRELATIONMISSING00000',
            'outcome' => ReferenceTaskOutcome::SkippedMissingTarget->value,
        ], Cache::get(CacheReferenceTaskResultRecorder::cacheKey($job->idempotencyFingerprint())));
    }

    public function test_serialized_payload_contains_only_safe_references_and_queue_metadata(): void
    {
        $job = new ProcessReferenceTask(
            'reference-operation:payload',
            'reference-target:payload',
            '01K1CORRELATIONPAYLOAD000000',
        );

        Queue::connection('database')->push($job);

        $payload = json_decode(DB::table('jobs')->sole()->payload, true, flags: JSON_THROW_ON_ERROR);
        $command = unserialize($payload['data']['command']);

        $this->assertInstanceOf(ProcessReferenceTask::class, $command);
        $this->assertSame('reference-operation:payload', $command->operationReference);
        $this->assertSame('reference-target:payload', $command->targetReference);
        $this->assertSame('01K1CORRELATIONPAYLOAD000000', $command->correlationId);
        $this->assertSame('default', $command->queue);
        $this->assertTrue($command->afterCommit);
        $this->assertStringNotContainsString('password', $payload['data']['command']);
        $this->assertStringNotContainsString('request_body', $payload['data']['command']);
    }

    public function test_concurrent_duplicate_execution_is_released_without_running_the_handler(): void
    {
        $job = (new ProcessReferenceTask('reference-operation:overlap'))
            ->withFakeQueueInteractions();
        $middleware = $job->middleware()[0];
        $lock = Cache::lock($middleware->getLockKey($job), 75);
        $handled = false;

        $this->assertTrue($lock->get());

        try {
            $middleware->handle($job, function () use (&$handled): void {
                $handled = true;
            });
        } finally {
            $lock->release();
        }

        $job->assertReleased(10);
        $this->assertFalse($handled);
    }
}

final class CountingReferenceTaskResultRecorder implements ReferenceTaskResultRecorder
{
    public int $attempts = 0;

    public int $effects = 0;

    public function __construct(private readonly ReferenceTaskResultRecorder $recorder) {}

    public function recordOnce(
        string $idempotencyFingerprint,
        string $correlationId,
        ReferenceTaskOutcome $outcome,
        int $lifetimeSeconds,
    ): bool {
        $this->attempts++;
        $recorded = $this->recorder->recordOnce(
            $idempotencyFingerprint,
            $correlationId,
            $outcome,
            $lifetimeSeconds,
        );

        if ($recorded) {
            $this->effects++;
        }

        return $recorded;
    }
}

final class PartialSuccessReferenceTaskResultRecorder implements ReferenceTaskResultRecorder
{
    public int $attempts = 0;

    public int $effects = 0;

    private bool $failureRaised = false;

    public function __construct(private readonly ReferenceTaskResultRecorder $recorder) {}

    public function recordOnce(
        string $idempotencyFingerprint,
        string $correlationId,
        ReferenceTaskOutcome $outcome,
        int $lifetimeSeconds,
    ): bool {
        $this->attempts++;
        $recorded = $this->recorder->recordOnce(
            $idempotencyFingerprint,
            $correlationId,
            $outcome,
            $lifetimeSeconds,
        );

        if ($recorded) {
            $this->effects++;
        }

        if (! $this->failureRaised) {
            $this->failureRaised = true;

            throw new RetryableJobException('reference_partial_failure');
        }

        return $recorded;
    }
}

final class PermanentFailureReferenceTaskResultRecorder implements ReferenceTaskResultRecorder
{
    public function recordOnce(
        string $idempotencyFingerprint,
        string $correlationId,
        ReferenceTaskOutcome $outcome,
        int $lifetimeSeconds,
    ): bool {
        throw new NonRetryableJobException('reference_permanent_failure');
    }
}

final class UnexpectedFailureReferenceTaskResultRecorder implements ReferenceTaskResultRecorder
{
    public function __construct(private readonly string $sensitiveInput) {}

    public function recordOnce(
        string $idempotencyFingerprint,
        string $correlationId,
        ReferenceTaskOutcome $outcome,
        int $lifetimeSeconds,
    ): bool {
        throw new RuntimeException($this->sensitiveInput);
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string|Stringable, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
    }
}
