<?php

namespace Tests\Feature;

use App\Jobs\ProcessReferenceTask;
use App\Observability\Alerts\AlertSink;
use App\Observability\Health\DependencyHealthProbe;
use App\Observability\Health\HealthCheckResult;
use App\Observability\Monitoring\OperationalState;
use App\Observability\Monitoring\QueueMonitor;
use App\Observability\OperationalTelemetry;
use App\Observability\TelemetrySanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Log\AbstractLogger;
use Stringable;
use Tests\TestCase;

class ObservabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_liveness_is_process_only_even_when_dependencies_are_unhealthy(): void
    {
        $this->app->bind(DependencyHealthProbe::class, fn () => new SyntheticHealthProbe([
            HealthCheckResult::unhealthy('database', 'synthetic outage'),
        ]));

        $this->getJson('/health/live')
            ->assertOk()
            ->assertExactJson(['status' => 'healthy']);
    }

    public function test_readiness_reports_healthy_degraded_and_unhealthy_without_public_details(): void
    {
        $this->app->bind(DependencyHealthProbe::class, fn () => new SyntheticHealthProbe([
            HealthCheckResult::healthy('database'),
            HealthCheckResult::degraded('openfoodfacts', 'optional provider unavailable'),
        ]));
        $this->getJson('/health/ready')->assertOk()->assertExactJson(['status' => 'degraded']);

        $private = 'password=synthetic-private-value';
        $this->app->bind(DependencyHealthProbe::class, fn () => new SyntheticHealthProbe([
            HealthCheckResult::unhealthy('queue_backend', $private),
        ]));
        $response = $this->getJson('/health/ready')->assertStatus(503)->assertExactJson(['status' => 'unhealthy']);
        $this->assertStringNotContainsString($private, $response->getContent());
    }

    public function test_request_correlation_accepts_only_bounded_safe_references(): void
    {
        $accepted = '01K1HTTPREQUESTCORRELATION000';
        $this->getJson('/health/live', ['X-Correlation-ID' => $accepted])
            ->assertHeader('X-Correlation-ID', $accepted);

        $generated = $this->getJson('/health/live', [
            'X-Correlation-ID' => 'private recipe instructions',
        ])->headers->get('X-Correlation-ID');

        $this->assertIsString($generated);
        $this->assertTrue(Str::isUlid($generated));
    }

    public function test_internal_health_command_is_detailed_but_safe(): void
    {
        $private = 'token=synthetic-secret-value';
        $this->app->bind(DependencyHealthProbe::class, fn () => new SyntheticHealthProbe([
            HealthCheckResult::unhealthy('database', $private),
        ]));

        $this->artisan('app:health')
            ->expectsOutputToContain('database: unhealthy')
            ->doesntExpectOutputToContain($private)
            ->assertFailed();
    }

    public function test_allowlist_sanitizer_excludes_private_fields_and_unsafe_dimension_values(): void
    {
        $private = 'correct-horse-battery-staple';
        $safe = app(TelemetrySanitizer::class)->sanitize([
            'operation' => 'recipe.import',
            'outcome' => 'success',
            'user_id' => 99,
            'recipe_id' => 42,
            'ingredient_text' => $private,
            'instruction_text' => $private,
            'diary_entry' => $private,
            'target_data' => $private,
            'import_source' => $private,
            'ocr_text' => $private,
            'password' => $private,
            'safe_error_code' => 'password='.$private,
        ]);

        $encoded = json_encode($safe, JSON_THROW_ON_ERROR);
        $this->assertSame('recipe.import', $safe['operation']);
        $this->assertSame('[redacted]', $safe['safe_error_code']);
        $this->assertStringNotContainsString($private, $encoded);
        $this->assertArrayNotHasKey('user_id', $safe);
        $this->assertArrayNotHasKey('recipe_id', $safe);
    }

    public function test_structured_telemetry_has_safe_schema_and_no_private_payload(): void
    {
        $logger = new ObservabilityRecordingLogger;
        Log::swap($logger);
        app(OperationalTelemetry::class)->event('synthetic_event', [
            'correlation_id' => '01K1SAFECORRELATION000000000',
            'operation' => 'recipe.import',
            'outcome' => 'success',
            'recipe_text' => 'synthetic private recipe',
        ]);

        $record = $logger->records[0];
        $this->assertSame('info', $record['level']);
        $this->assertSame('synthetic_event', $record['message']);
        $this->assertSame('01K1SAFECORRELATION000000000', $record['context']['correlation_id']);
        $this->assertSame('recipe.import', $record['context']['operation']);
        $this->assertSame('success', $record['context']['outcome']);
        $this->assertArrayNotHasKey('recipe_text', $record['context']);
        $this->assertArrayHasKey('environment', $record['context']);
    }

    public function test_worker_freshness_is_independent_from_queue_backend_reachability(): void
    {
        Date::setTestNow('2026-08-27 12:00:00 UTC');
        $state = app(OperationalState::class);
        $this->assertSame('unhealthy', $state->workerHealth('default')->state);
        $this->assertSame(0, DB::table('jobs')->count());

        $state->recordWorker('default');
        $this->assertSame('healthy', $state->workerHealth('default')->state);

        Date::setTestNow(Date::now()->addSeconds(config('observability.worker_stale_seconds') + 1));
        $this->assertSame('unhealthy', $state->workerHealth('default')->state);
    }

    public function test_scheduler_and_pruning_freshness_handle_missing_fresh_and_stale_states(): void
    {
        Date::setTestNow('2026-08-27 12:00:00 UTC');
        $state = app(OperationalState::class);
        $this->assertSame('unhealthy', $state->schedulerHealth()->state);
        $this->assertSame('unhealthy', $state->pruningHealth()->state);

        $state->recordScheduler();
        $state->recordPrune();
        $this->assertSame('healthy', $state->schedulerHealth()->state);
        $this->assertSame('healthy', $state->pruningHealth()->state);

        Date::setTestNow(Date::now()->addSeconds(config('observability.prune_stale_seconds') + 1));
        $this->assertSame('unhealthy', $state->schedulerHealth()->state);
        $this->assertSame('unhealthy', $state->pruningHealth()->state);
    }

    public function test_queue_depth_and_oldest_age_thresholds_never_export_payloads(): void
    {
        Date::setTestNow('2026-08-27 12:00:00 UTC');
        config()->set('observability.queue_depth_warning', 2);
        config()->set('observability.queue_depth_critical', 4);
        config()->set('observability.oldest_job_warning_seconds', 60);
        config()->set('observability.oldest_job_critical_seconds', 120);
        DB::table('jobs')->insert([
            ['queue' => 'default', 'payload' => 'private recipe payload one', 'attempts' => 0, 'reserved_at' => null, 'available_at' => Date::now()->timestamp, 'created_at' => Date::now()->subSeconds(70)->timestamp],
            ['queue' => 'default', 'payload' => 'private recipe payload two', 'attempts' => 0, 'reserved_at' => null, 'available_at' => Date::now()->timestamp, 'created_at' => Date::now()->timestamp],
        ]);

        $snapshot = collect(app(QueueMonitor::class)->queues())->firstWhere('queue', 'default');
        $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);
        $this->assertSame(2, $snapshot['depth']);
        $this->assertSame(70, $snapshot['oldest_age_seconds']);
        $this->assertSame('warning', $snapshot['state']);
        $this->assertStringNotContainsString('private recipe', $encoded);
    }

    public function test_failed_job_metrics_exclude_serialized_payload_and_respect_age(): void
    {
        Date::setTestNow('2026-08-27 12:00:00 UTC');
        DB::table('failed_jobs')->insert([
            'uuid' => 'synthetic-failure',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => 'private serialized payload',
            'exception' => 'sanitized',
            'failed_at' => Date::now()->subMinutes(10),
        ]);

        $snapshot = app(QueueMonitor::class)->failedJobs();
        $this->assertSame(1, $snapshot['count']);
        $this->assertSame(600, $snapshot['oldest_age_seconds']);
        $this->assertStringNotContainsString('private serialized payload', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    public function test_repeated_replay_failures_are_detectable_without_payload_storage(): void
    {
        $state = app(OperationalState::class);
        $this->assertSame(1, $state->recordReplayFailure('safe-job-uuid'));
        $this->assertSame(2, $state->recordReplayFailure('safe-job-uuid'));
        $this->assertNull(Cache::get('private serialized payload'));
    }

    public function test_alert_destination_is_fakeable_and_smoke_test_has_safe_fields(): void
    {
        config()->set('observability.queue_depth_warning', 1);
        config()->set('observability.queue_depth_critical', 2);
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => 'private payload', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
        ]);
        $sink = new RecordingAlertSink;
        $this->app->instance(AlertSink::class, $sink);

        $this->artisan('observability:monitor')->assertSuccessful();

        $encoded = json_encode($sink->alerts, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('queue_backlog', $encoded);
        $this->assertStringContainsString('queue_worker_unavailable', $encoded);
        $this->assertStringNotContainsString('private payload', $encoded);
    }

    public function test_http_job_and_provider_safe_events_share_correlation_context(): void
    {
        $correlationId = '01K1CORRELATIONCHAIN00000000';
        $this->getJson('/health/live', ['X-Correlation-ID' => $correlationId])->assertOk();
        $job = new ProcessReferenceTask('reference-operation:correlation-chain', 'safe-target');
        $this->assertSame($correlationId, $job->correlationId);

        $logger = new ObservabilityRecordingLogger;
        Log::swap($logger);
        app()->call([$job, 'handle']);
        $started = collect($logger->records)->firstWhere('message', 'queued_job_started');
        $this->assertIsArray($started);
        $this->assertSame('info', $started['level']);
        $this->assertSame($correlationId, $started['context']['correlation_id']);
        $this->assertSame(ProcessReferenceTask::OPERATION_TYPE, $started['context']['operation']);
    }

    public function test_failure_spike_and_slow_provider_fixtures_trigger_safe_alerts(): void
    {
        config()->set('observability.failure_warning_count', 2);
        config()->set('observability.failure_critical_count', 4);
        config()->set('observability.provider_slow_warning_ms', 1);
        $telemetry = app(OperationalTelemetry::class);
        $telemetry->counter('queue.final_failure', [
            'job_type' => ProcessReferenceTask::class,
            'queue' => 'default',
            'outcome' => 'failed',
        ], 4);
        $telemetry->timing('provider.request', 2, [
            'provider' => 'openfoodfacts',
            'operation' => 'product.lookup',
            'outcome' => 'success',
        ]);
        $telemetry->counter('provider.slow', [
            'provider' => 'openfoodfacts',
            'outcome' => 'slow',
        ]);
        $sink = new RecordingAlertSink;
        $this->app->instance(AlertSink::class, $sink);

        $this->artisan('observability:monitor')->assertSuccessful();
        $encoded = json_encode($sink->alerts, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('final_failure_spike', $encoded);
        $this->assertStringContainsString('provider_latency', $encoded);
        $this->assertStringNotContainsString('private', $encoded);
    }
}

final readonly class SyntheticHealthProbe implements DependencyHealthProbe
{
    /** @param list<HealthCheckResult> $checks */
    public function __construct(private array $checks) {}

    public function check(): array
    {
        return $this->checks;
    }
}

final class RecordingAlertSink implements AlertSink
{
    /** @var list<array{category: string, state: string, context: array}> */
    public array $alerts = [];

    public function send(string $category, string $state, array $context = []): void
    {
        $this->alerts[] = compact('category', 'state', 'context');
    }
}

final class ObservabilityRecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array}> */
    public array $records = [];

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }
}
