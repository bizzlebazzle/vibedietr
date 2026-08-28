<?php

namespace App\Providers;

use App\Security\Limits\LimiterIdentity;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class SecurityControlServiceProvider extends ServiceProvider
{
    public function boot(LimiterIdentity $identities): void
    {
        RateLimiter::for('public-search', fn (Request $request): Limit => Limit::perMinute(
            (int) config('security.throttles.public_search.attempts'),
        )->by('public-search:'.$identities->request($request)));

        RateLimiter::for('barcode-lookup', fn (Request $request): array => [
            Limit::perMinute((int) config('security.throttles.barcode_user.attempts'))
                ->by('barcode:user:'.$identities->request($request)),
            Limit::perMinute((int) config('security.throttles.barcode_global.attempts'))
                ->by('barcode:global'),
        ]);

        RateLimiter::for('sharing', fn (Request $request): Limit => Limit::perMinute(
            (int) config('security.throttles.sharing.attempts'),
        )->by('sharing:'.$identities->request($request)));

        RateLimiter::for('recipe-import', fn (Request $request): array => [
            Limit::perHour((int) config('security.throttles.import_user.attempts'))
                ->by('recipe-import:user:'.$identities->request($request)),
            Limit::perHour((int) config('security.throttles.import_global.attempts'))
                ->by('recipe-import:global'),
        ]);

        RateLimiter::for('security-sensitive', fn (Request $request): Limit => Limit::perMinute(
            (int) config('security.throttles.security.attempts'),
        )->by('security:'.$identities->request($request)));
    }
}
