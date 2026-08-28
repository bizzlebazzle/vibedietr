<?php

namespace App\Observability;

final class TelemetrySanitizer
{
    private const ALLOWED_FIELDS = [
        'alert_category', 'attempt_count', 'correlation_id', 'count', 'duration_ms',
        'environment', 'exception_class', 'failure_category', 'http_status',
        'job_class', 'job_identifier', 'job_type', 'metric', 'operation',
        'operation_type', 'outcome', 'provider', 'queue', 'recipient_role',
        'release', 'safe_error_code', 'state', 'threshold', 'value', 'window_seconds',
        'limiter', 'route_category', 'content_validation_result',
        'resource_identifier',
    ];

    /** @return array<string, bool|float|int|string|null> */
    public function sanitize(array $context): array
    {
        $safe = [];

        foreach ($context as $key => $value) {
            if (! is_string($key) || ! in_array($key, self::ALLOWED_FIELDS, true)) {
                continue;
            }

            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $safe[$key] = $value;
            } elseif (is_string($value)) {
                $bounded = mb_substr($value, 0, 160);
                $safe[$key] = preg_match('~\A[A-Za-z0-9_.:\\\\/-]+\z~', $bounded) === 1
                    ? $bounded
                    : '[redacted]';
            }
        }

        return $safe;
    }
}
