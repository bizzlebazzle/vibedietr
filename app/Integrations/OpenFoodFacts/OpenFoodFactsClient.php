<?php

namespace App\Integrations\OpenFoodFacts;

use App\Domain\Catalogue\Barcode;
use App\Domain\Shared\ExactJsonDecoder;
use App\Observability\CorrelationContext;
use App\Observability\OperationalTelemetry;
use App\Queue\CorrelationId;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;

final readonly class OpenFoodFactsClient
{
    public const PROVIDER = 'openfoodfacts';

    private const FIELDS = [
        'code', 'product_name', 'quantity', 'categories_tags', 'states_tags',
        'keywords', '_keywords', 'nutriments', 'serving_quantity',
        'serving_size', 'image_front_small_url', 'image_front_url',
    ];

    public function __construct(private OpenFoodFactsProductMapper $mapper) {}

    public function lookup(string $barcode, ?string $correlationId = null): OpenFoodFactsLookupResult
    {
        $barcode = Barcode::normalize($barcode);
        $correlationId = CorrelationId::resolve($correlationId ?? app(CorrelationContext::class)->get());
        $startedAt = microtime(true);
        try {
            if (! $this->hasUsableConfiguration()) {
                return $this->failure(OpenFoodFactsLookupStatus::PermanentFailure, $correlationId, $barcode, 0);
            }

            $attempts = max(1, (int) config('services.openfoodfacts.attempts', 2));
            $lastResponse = null;

            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                try {
                    $response = Http::acceptJson()
                        ->withUserAgent((string) config('services.openfoodfacts.user_agent'))
                        ->connectTimeout((float) config('services.openfoodfacts.connect_timeout', 2))
                        ->timeout((float) config('services.openfoodfacts.timeout', 5))
                        ->get($this->url($barcode), ['fields' => implode(',', self::FIELDS)]);
                } catch (ConnectionException) {
                    if ($attempt < $attempts) {
                        $this->sleepBeforeRetry($attempt);

                        continue;
                    }

                    return $this->failure(OpenFoodFactsLookupStatus::Unavailable, $correlationId, $barcode, $attempt);
                }

                $lastResponse = $response;

                if ($response->notFound()) {
                    return OpenFoodFactsLookupResult::failure(OpenFoodFactsLookupStatus::NotFound, $correlationId);
                }

                if ($this->isRateLimited($response)) {
                    $retryAfter = $this->retryAfterSeconds($response);

                    if ($attempt < $attempts && $retryAfter !== null && $this->safeToRetryAfter($retryAfter)) {
                        $this->sleepSeconds($retryAfter);

                        continue;
                    }

                    return $this->failure(OpenFoodFactsLookupStatus::RateLimited, $correlationId, $barcode, $attempt, $response->status());
                }

                if ($this->isTransient($response)) {
                    if ($attempt < $attempts) {
                        $this->sleepBeforeRetry($attempt);

                        continue;
                    }

                    return $this->failure(OpenFoodFactsLookupStatus::Unavailable, $correlationId, $barcode, $attempt, $response->status());
                }

                if (! $response->successful()) {
                    return $this->failure(OpenFoodFactsLookupStatus::PermanentFailure, $correlationId, $barcode, $attempt, $response->status());
                }

                try {
                    $decoded = ExactJsonDecoder::decodeObject($response->body());
                    $product = $this->mapper->map($decoded);

                    if (Barcode::normalize($product->code) !== $barcode) {
                        throw new InvalidOpenFoodFactsResponse;
                    }
                } catch (InvalidArgumentException|JsonException|InvalidOpenFoodFactsResponse) {
                    return $this->failure(OpenFoodFactsLookupStatus::InvalidResponse, $correlationId, $barcode, $attempt, $response->status());
                }

                return OpenFoodFactsLookupResult::success($correlationId, $product);
            }

            return $this->failure(OpenFoodFactsLookupStatus::Unavailable, $correlationId, $barcode, $attempts, $lastResponse?->status());
        } finally {
            app(OperationalTelemetry::class)->timing('provider.request', (microtime(true) - $startedAt) * 1000, [
                'provider' => self::PROVIDER,
                'correlation_id' => $correlationId,
                'operation' => 'product.lookup',
            ]);
        }
    }

    private function hasUsableConfiguration(): bool
    {
        return trim((string) config('services.openfoodfacts.base_url')) !== ''
            && trim((string) config('services.openfoodfacts.api_version')) !== ''
            && trim((string) config('services.openfoodfacts.user_agent')) !== ''
            && (float) config('services.openfoodfacts.connect_timeout') > 0
            && (float) config('services.openfoodfacts.timeout') > 0
            && (int) config('services.openfoodfacts.attempts') > 0;
    }

    private function url(string $barcode): string
    {
        $baseUrl = rtrim((string) config('services.openfoodfacts.base_url'), '/');
        $apiVersion = trim((string) config('services.openfoodfacts.api_version'), '/');

        return "{$baseUrl}/api/{$apiVersion}/product/".rawurlencode($barcode).'.json';
    }

    private function isTransient(Response $response): bool
    {
        return $response->status() === 408 || ($response->serverError() && $response->status() !== 503);
    }

    private function isRateLimited(Response $response): bool
    {
        return $response->status() === 429 || $response->status() === 503;
    }

    private function retryAfterSeconds(Response $response): ?int
    {
        $header = (string) $response->header('Retry-After');

        if (trim($header) === '') {
            return null;
        }

        if (ctype_digit(trim($header))) {
            return (int) trim($header);
        }

        $timestamp = strtotime($header);

        return $timestamp === false ? null : max(0, $timestamp - time());
    }

    private function safeToRetryAfter(int $seconds): bool
    {
        return $seconds <= max(0, (int) config('services.openfoodfacts.max_retry_after', 1));
    }

    private function sleepBeforeRetry(int $attempt): void
    {
        $backoff = config('services.openfoodfacts.backoff_ms', [100]);
        $milliseconds = is_array($backoff)
            ? (int) ($backoff[$attempt - 1] ?? end($backoff) ?: 0)
            : (int) $backoff;

        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    private function sleepSeconds(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    private function failure(
        OpenFoodFactsLookupStatus $status,
        string $correlationId,
        string $barcode,
        int $attempts,
        ?int $httpStatus = null,
    ): OpenFoodFactsLookupResult {
        Log::warning('openfoodfacts_lookup_failed', array_filter([
            'provider' => self::PROVIDER,
            'correlation_id' => $correlationId,
            'failure_category' => $status->value,
            'http_status' => $httpStatus,
            'attempt_count' => $attempts,
            'barcode_reference' => substr(hash('sha256', $barcode), 0, 16),
        ], static fn (mixed $value): bool => $value !== null));

        return OpenFoodFactsLookupResult::failure($status, $correlationId);
    }
}
