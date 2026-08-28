<?php

namespace Tests\Feature;

use App\Jobs\ProcessPastedRecipeImport;
use App\Logging\RedactSensitiveContext;
use App\Models\RecipeImport;
use App\Models\User;
use App\Observability\TelemetrySanitizer;
use App\Security\Redaction\SensitiveDataRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Tests\TestCase;

class RedactionPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_reusable_redactor_removes_credentials_imports_filenames_paths_and_provider_payloads(): void
    {
        $secrets = [
            'distinctive-password', 'distinctive-otp', 'distinctive-recovery',
            'distinctive-api-token', 'distinctive-import-text', 'distinctive-source-bytes',
            'private-name.html', '/private/storage/path', 'distinctive-provider-body',
        ];
        $safe = app(SensitiveDataRedactor::class)->redact([
            'password' => $secrets[0],
            'totp' => $secrets[1],
            'recovery_code' => $secrets[2],
            'api_key' => $secrets[3],
            'source_text' => $secrets[4],
            'import_source_bytes' => $secrets[5],
            'original_filename' => $secrets[6],
            'storage_path' => $secrets[7],
            'nested' => ['provider_response' => $secrets[8], 'outcome' => 'failed'],
        ]);
        $encoded = json_encode($safe, JSON_THROW_ON_ERROR);

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
        $this->assertSame('failed', $safe['nested']['outcome']);
    }

    public function test_logging_processor_redacts_sensitive_context_before_handler_storage(): void
    {
        $handler = new TestHandler(Level::Debug);
        $logger = new Logger('security-test', [$handler]);
        app(RedactSensitiveContext::class)($logger);
        $logger->warning('safe_event', [
            'password' => 'distinctive-log-password',
            'provider_payload' => ['body' => 'distinctive-provider-fixture'],
            'outcome' => 'failed',
        ]);

        $encoded = json_encode($handler->getRecords(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('distinctive-log-password', $encoded);
        $this->assertStringNotContainsString('distinctive-provider-fixture', $encoded);
        $this->assertStringContainsString('failed', $encoded);

        foreach (['stack', 'single', 'daily', 'slack', 'papertrail', 'stderr', 'syslog', 'errorlog'] as $channel) {
            $this->assertContains(RedactSensitiveContext::class, config("logging.channels.{$channel}.tap", []));
        }
    }

    public function test_metric_allowlist_excludes_private_or_high_cardinality_dimensions(): void
    {
        $safe = app(TelemetrySanitizer::class)->sanitize([
            'limiter' => 'recipe-import',
            'route_category' => 'import',
            'outcome' => 'rejected',
            'user_id' => 123,
            'filename' => 'distinctive-private-name',
            'source_url' => 'https://private.example/path',
            'import_content' => 'distinctive-import-content',
            'provider_payload' => 'distinctive-provider-content',
        ]);

        $this->assertSame([
            'limiter' => 'recipe-import',
            'route_category' => 'import',
            'outcome' => 'rejected',
        ], $safe);
    }

    public function test_exception_response_and_identifier_only_job_payload_exclude_private_values(): void
    {
        $secrets = [
            'distinctive-exception-password', 'distinctive-import-source',
            'private-upload-name.html', '/private/transient/path', 'distinctive-provider-payload',
        ];
        $this->withExceptionHandling();
        Route::post('/_dep03/exception', fn () => throw new \RuntimeException('safe failure'));
        $response = $this->post('/_dep03/exception', [
            'password' => $secrets[0], 'source_text' => $secrets[1],
            'filename' => $secrets[2], 'storage_path' => $secrets[3],
            'provider_payload' => $secrets[4],
        ])->assertServerError();

        $user = User::factory()->create();
        $import = RecipeImport::factory()->for($user, 'owner')->create(['source_text' => $secrets[1]]);
        DB::table('jobs')->delete();
        Queue::connection('database')->push(new ProcessPastedRecipeImport($import->id, $import->correlation_id));
        $payload = (string) DB::table('jobs')->sole()->payload;

        foreach ($secrets as $secret) {
            $response->assertDontSee($secret, false);
            $this->assertStringNotContainsString($secret, $payload);
        }
    }
}
