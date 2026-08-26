<?php

namespace Tests\Feature;

use App\Jobs\DeliverSecurityNotification;
use App\Jobs\ProcessReferenceTask;
use App\Queue\FailedJobPruner;
use App\Queue\JobFailureReporter;
use App\Queue\QueueName;
use App\Queue\Reference\CacheReferenceTaskResultRecorder;
use App\Queue\Reference\ReferenceTaskOutcome;
use App\Queue\Reference\ReferenceTaskResultRecorder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Psr\Log\AbstractLogger;
use Stringable;
use Tests\TestCase;

class QueueOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_topology_inventory_and_timeout_margin_are_consistent(): void
    {
        $this->assertSame(
            [QueueName::SECURITY_NOTIFICATIONS, QueueName::DEFAULT],
            config('queue-operations.queues'),
        );

        $inventory = config('queue-operations.jobs');
        $implementedJobs = collect(File::files(app_path('Jobs')))
            ->map(fn (\SplFileInfo $file): string => 'App\\Jobs\\'.$file->getBasename('.php'))
            ->sort()
            ->values()
            ->all();
        $inventoriedJobs = array_keys($inventory);
        sort($inventoriedJobs);

        $this->assertSame($implementedJobs, $inventoriedJobs);
        $this->assertSame(QueueName::SECURITY_NOTIFICATIONS, (new DeliverSecurityNotification('synthetic-intent'))->queue);
        $this->assertSame(QueueName::DEFAULT, (new ProcessReferenceTask('reference-operation:inventory'))->queue);
        $this->assertSame(30, $inventory[DeliverSecurityNotification::class]['timeout']);
        $this->assertSame(60, $inventory[ProcessReferenceTask::class]['timeout']);
        $this->assertSame(
            [QueueName::SECURITY_NOTIFICATIONS],
            config('queue-operations.workers.security-notifications.queues'),
        );

        $maximumTimeout = max(
            ...array_column(config('queue-operations.workers'), 'timeout'),
            ...array_column($inventory, 'timeout'),
        );
        $retryAfter = config('queue.connections.database.retry_after');
        $margin = config('queue-operations.retry_after_safety_margin_seconds');

        $this->assertSame(70, $maximumTimeout);
        $this->assertGreaterThanOrEqual($maximumTimeout + $margin, $retryAfter);
    }

    public function test_container_topology_matches_the_authoritative_worker_configuration(): void
    {
        $compose = File::get(base_path('compose.production-operations.yml'));

        foreach ([
            '--queue=security-notifications',
            '--timeout=40',
            '--queue=default',
            '--timeout=70',
            '--memory=256',
            '--max-jobs=500',
            '--max-time=3600',
            'schedule:work',
            'restart: unless-stopped',
        ] as $expected) {
            $this->assertStringContainsString($expected, $compose);
        }

        $this->assertSame(2, substr_count($compose, 'queue:work'));
        $this->assertSame(1, substr_count($compose, 'schedule:work'));
    }

    public function test_pruner_removes_metadata_at_the_exact_seven_day_boundary(): void
    {
        Date::setTestNow('2026-08-26 12:00:00 UTC');
        $this->failedJob('younger', ProcessReferenceTask::class, Date::now()->subHours(168)->addSecond());
        $this->failedJob('boundary', ProcessReferenceTask::class, Date::now()->subHours(168));
        $this->failedJob('older', ProcessReferenceTask::class, Date::now()->subHours(169));

        $this->artisan('queue:prune-operational-failures')
            ->expectsOutputToContain('2 expired')
            ->assertSuccessful();

        $this->assertDatabaseHas('failed_jobs', ['uuid' => 'younger']);
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => 'boundary']);
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => 'older']);
    }

    public function test_terminal_personal_payload_is_removed_without_leaking_command_output(): void
    {
        Date::setTestNow('2026-08-26 12:00:00 UTC');
        $privateValue = 'synthetic-person@example.test';
        $this->failedJob(
            'personal',
            'Tests\\Fixtures\\SyntheticPrivatePayloadJob',
            Date::now(),
            ['synthetic_private_value' => $privateValue],
        );

        $this->artisan('queue:prune-operational-failures')
            ->expectsOutputToContain('1 personal-payload')
            ->doesntExpectOutputToContain($privateValue)
            ->assertSuccessful();

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => 'personal']);
    }

    public function test_final_personal_failure_is_alerted_safely_and_never_retained(): void
    {
        $privateValue = 'synthetic-person@example.test';
        $logger = new PrivacyRecordingLogger;
        Log::swap($logger);
        app('queue')->connection('database')->push(
            new SyntheticPrivateFailureJob($privateValue),
        );

        $this->artisan('queue:work', [
            'connection' => 'database',
            '--queue' => QueueName::DEFAULT,
            '--once' => true,
            '--sleep' => 0,
            '--tries' => 1,
            '--timeout' => 10,
        ])->assertSuccessful();

        $this->assertDatabaseCount('failed_jobs', 0);
        $encoded = json_encode($logger->records, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($privateValue, $encoded);
        $this->assertCount(1, array_filter(
            $logger->records,
            fn (array $record): bool => $record['message'] === 'queued_job_failed',
        ));
    }

    public function test_unknown_or_malformed_payload_classification_fails_private(): void
    {
        $pruner = app(FailedJobPruner::class);

        $this->assertSame('personal', $pruner->classification('not-json'));
        $this->assertSame('personal', $pruner->classification(json_encode([
            'displayName' => 'UninventoriedJob',
        ], JSON_THROW_ON_ERROR)));
    }

    public function test_representative_schedule_uses_a_real_overlap_mutex(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->firstWhere('description', 'queue-prune-operational-failures');

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
        $this->assertSame(10, $event->expiresAt);
        $this->assertSame('UTC', $event->timezone);

        $event->mutex->forget($event);
        $this->assertTrue($event->mutex->create($event));
        $this->assertFalse($event->mutex->create($event));
        $event->mutex->forget($event);
        $this->assertTrue($event->mutex->create($event));
        $event->mutex->forget($event);
    }

    public function test_scheduler_and_graceful_restart_commands_smoke(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('queue:prune-operational-failures')
            ->assertSuccessful();

        Cache::forget('illuminate:queue:restart');
        $this->artisan('queue:restart')->assertSuccessful();
        $this->assertIsInt(Cache::get('illuminate:queue:restart'));
    }

    public function test_operator_replay_uses_business_idempotency_and_preserves_correlation(): void
    {
        $job = new ProcessReferenceTask(
            'reference-operation:operator-replay',
            'reference-target:operator-replay',
            '01K1CORRELATIONREPLAY0000000',
        );
        $recorder = new ReplayCountingReferenceTaskResultRecorder(
            app(CacheReferenceTaskResultRecorder::class),
        );
        app()->instance(ReferenceTaskResultRecorder::class, $recorder);
        $job->handle($recorder);

        DB::table('jobs')->delete();
        app('queue')->connection('database')->push($job);
        $queued = DB::table('jobs')->sole();
        DB::table('jobs')->delete();
        $payload = json_decode($queued->payload, true, flags: JSON_THROW_ON_ERROR);
        $uuid = $payload['uuid'];
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => QueueName::DEFAULT,
            'payload' => $queued->payload,
            'exception' => 'Synthetic sanitized failure.',
            'failed_at' => Date::now(),
        ]);

        $this->artisan('queue:retry', ['id' => [$uuid]])->assertSuccessful();
        $this->artisan('queue:work', [
            'connection' => 'database',
            '--queue' => QueueName::DEFAULT,
            '--once' => true,
            '--sleep' => 0,
            '--tries' => 3,
            '--timeout' => 70,
        ])->assertSuccessful();

        $this->assertSame(2, $recorder->attempts);
        $this->assertSame(1, $recorder->effects);
        $this->assertSame(
            '01K1CORRELATIONREPLAY0000000',
            Cache::get(CacheReferenceTaskResultRecorder::cacheKey($job->idempotencyFingerprint()))['correlation_id'],
        );
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
    }

    public function test_operator_can_forget_one_failed_record_without_flushing_others(): void
    {
        $this->failedJob('forget-me', ProcessReferenceTask::class, Date::now());
        $this->failedJob('keep-me', ProcessReferenceTask::class, Date::now());

        $this->artisan('queue:forget', ['id' => 'forget-me'])->assertSuccessful();

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => 'forget-me']);
        $this->assertDatabaseHas('failed_jobs', ['uuid' => 'keep-me']);
    }

    /** @param array<string, string> $extra */
    private function failedJob(string $uuid, string $jobClass, mixed $failedAt, array $extra = []): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => QueueName::DEFAULT,
            'payload' => json_encode(array_merge([
                'uuid' => $uuid,
                'displayName' => $jobClass,
            ], $extra), JSON_THROW_ON_ERROR),
            'exception' => 'Synthetic sanitized failure.',
            'failed_at' => $failedAt,
        ]);
    }
}

final class SyntheticPrivateFailureJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 5;

    public function __construct(public readonly string $syntheticPrivateValue)
    {
        $this->onQueue(QueueName::DEFAULT);
    }

    public function handle(): void
    {
        throw new \RuntimeException('synthetic_private_fixture_failed');
    }

    public function failed(?\Throwable $exception): void
    {
        app(JobFailureReporter::class)->report(
            self::class,
            'synthetic_private_fixture.fail',
            $this->job?->uuid(),
            hash('sha256', 'synthetic-private-fixture'),
            '01K1SYNTHETICPRIVATEFIXTURE00',
            QueueName::DEFAULT,
            $this->attempts(),
            $exception ?? new \RuntimeException('synthetic_private_fixture_failed'),
        );
    }
}

final class PrivacyRecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}

final class ReplayCountingReferenceTaskResultRecorder implements ReferenceTaskResultRecorder
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
        $this->effects += (int) $recorded;

        return $recorded;
    }
}
