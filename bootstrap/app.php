<?php

use App\Console\Commands\BootstrapAdministrator;
use App\Console\Commands\BreakGlassReplaceAdministrator;
use App\Console\Commands\ExpireAdministratorPromotionRequests;
use App\Console\Commands\ProductionConfigurationCheck;
use App\Console\Commands\PruneOperationalFailedJobs;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        BootstrapAdministrator::class,
        BreakGlassReplaceAdministrator::class,
        ExpireAdministratorPromotionRequests::class,
        PruneOperationalFailedJobs::class,
        ProductionConfigurationCheck::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
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
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
