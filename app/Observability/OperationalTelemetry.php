<?php

namespace App\Observability;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;

final class OperationalTelemetry
{
    public function __construct(
        private readonly TelemetrySanitizer $sanitizer,
        private readonly Repository $cache,
    ) {}

    public function event(string $event, array $context = [], string $level = 'info'): void
    {
        $safe = $this->sanitizer->sanitize(array_merge([
            'environment' => app()->environment(),
            'release' => (string) config('observability.release', 'unknown'),
        ], $context));

        Log::log($level, $event, $safe);
    }

    public function counter(string $metric, array $labels = [], int $amount = 1): void
    {
        $safeLabels = $this->sanitizer->sanitize($labels);
        ksort($safeLabels);
        $key = 'observability:counter:'.hash('sha256', $metric.'|'.json_encode($safeLabels));
        $this->cache->add($key, 0, now()->addSeconds((int) config('observability.failure_window_seconds', 300)));
        $this->cache->increment($key, $amount);
        $totalKey = $this->totalKey($metric);
        $this->cache->add($totalKey, 0, now()->addSeconds((int) config('observability.failure_window_seconds', 300)));
        $this->cache->increment($totalKey, $amount);
        $this->event('operational_metric', array_merge($safeLabels, [
            'metric' => $metric,
            'value' => $amount,
        ]));
    }

    public function timing(string $metric, float $durationMs, array $labels = []): void
    {
        $this->event('operational_timing', array_merge($labels, [
            'metric' => $metric,
            'duration_ms' => round(max(0, $durationMs), 2),
        ]));
        if ($metric === 'provider.request' && $durationMs >= (int) config('observability.provider_slow_warning_ms', 3000)) {
            $this->counter('provider.slow', $labels);
        }

    }

    public function counterTotal(string $metric): int
    {
        return (int) $this->cache->get($this->totalKey($metric), 0);
    }

    private function totalKey(string $metric): string
    {
        return 'observability:counter:total:'.hash('sha256', $metric);
    }
}
