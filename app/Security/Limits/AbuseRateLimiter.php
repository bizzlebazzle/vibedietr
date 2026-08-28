<?php

namespace App\Security\Limits;

use App\Observability\OperationalTelemetry;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class AbuseRateLimiter
{
    public function __construct(private readonly OperationalTelemetry $telemetry) {}

    public function consume(string $limiter, string $identity, int $attempts, int $decaySeconds): void
    {
        $key = 'security-limit:'.$limiter.':'.$identity;

        if (RateLimiter::tooManyAttempts($key, $attempts)) {
            $retryAfter = RateLimiter::availableIn($key);
            $this->telemetry->counter('security.throttled', [
                'limiter' => $limiter,
                'outcome' => 'rejected',
                'route_category' => $limiter,
            ]);

            throw new TooManyRequestsHttpException($retryAfter, 'Too many attempts. Please try again later.');
        }

        RateLimiter::hit($key, $decaySeconds);
    }
}
