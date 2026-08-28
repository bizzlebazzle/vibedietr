<?php

use App\Console\Commands\ApplicationHealth;
use App\Console\Commands\BootstrapAdministrator;
use App\Console\Commands\BreakGlassReplaceAdministrator;
use App\Console\Commands\ExpireAdministratorPromotionRequests;
use App\Console\Commands\MonitorOperations;
use App\Console\Commands\ProductionConfigurationCheck;
use App\Console\Commands\PruneOperationalFailedJobs;
use App\Console\Commands\RecordSchedulerHeartbeat;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\AttachCorrelationId;
use App\Http\Middleware\RejectOversizedRequest;
use App\Observability\CorrelationContext;
use App\Observability\OperationalTelemetry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');
            Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');
        },
    )
    ->withCommands([
        ApplicationHealth::class,
        BootstrapAdministrator::class,
        BreakGlassReplaceAdministrator::class,
        ExpireAdministratorPromotionRequests::class,
        MonitorOperations::class,
        PruneOperationalFailedJobs::class,
        ProductionConfigurationCheck::class,
        RecordSchedulerHeartbeat::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(AttachCorrelationId::class);
        $middleware->append(AddSecurityHeaders::class);
        $middleware->append(RejectOversizedRequest::class);
        $middleware->trustHosts(
            at: fn (): array => array_map(
                fn (string $host): string => '^'.preg_quote($host, '/').'$',
                config('production.trusted_hosts', []),
            ),
            subdomains: false,
        );
        $middleware->trimStrings(except: [
            'components.*.updates.originalText',
            'components.*.updates.instructionText',
            'components.*.updates.ingredients.*.original_text',
            'components.*.updates.steps.*.text',
            'source_text',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash([
            'password', 'password_confirmation', 'token', 'api_key', 'secret',
            'authorization', 'cookie', 'session', 'recovery_code', 'totp', 'otp',
            'original_text', 'instruction_text', 'diary_entry', 'target_data',
            'import_source', 'ocr_text',
            'source_text',
            'import_source_bytes', 'extraction_text', 'filename', 'original_filename',
            'storage_path', 'local_path', 'file_path', 'provider_payload',
            'provider_request', 'provider_response', 'request_body',
        ]);
        $exceptions->render(function (Throwable $exception, Request $request) {
            if ($exception instanceof AuthenticationException
                || $exception instanceof AuthorizationException
                || $exception instanceof ValidationException
                || $exception instanceof ModelNotFoundException
                || ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500)) {
                return null;
            }

            $sensitive = array_map('strtolower', (array) config('security.sensitive_keys', []));
            $submitted = array_map('strtolower', $request->keys());
            if (array_intersect($sensitive, $submitted) === []) {
                return null;
            }

            $message = 'The request could not be completed.';

            return $request->expectsJson()
                ? response()->json(['message' => $message], 500)
                : response($message, 500, ['Content-Type' => 'text/plain; charset=UTF-8']);
        });
        $exceptions->context(fn (): array => [
            'correlation_id' => app(CorrelationContext::class)->get(),
            'environment' => app()->environment(),
            'release' => (string) config('observability.release', 'unknown'),
        ]);
        $exceptions->report(function (Throwable $exception): bool {
            app(OperationalTelemetry::class)->counter('application.exception', [
                'exception_class' => $exception::class,
                'operation' => app()->runningInConsole() ? 'console' : 'http.request',
                'correlation_id' => app(CorrelationContext::class)->get(),
                'outcome' => 'failed',
            ]);

            return false;
        });
    })->create();
