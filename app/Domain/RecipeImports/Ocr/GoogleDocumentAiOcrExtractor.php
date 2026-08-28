<?php

namespace App\Domain\RecipeImports\Ocr;

use App\Queue\Exceptions\NonRetryableJobException;
use App\Queue\Exceptions\RetryableJobException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

final class GoogleDocumentAiOcrExtractor implements ManagedOcrExtractor
{
    public const PROVIDER = 'google_document_ai';

    public function enabled(): bool
    {
        return (bool) config('production.ocr.google.enabled');
    }

    public function extract(string $canonicalBytes, string $correlationId): OcrResult
    {
        $endpointHost = parse_url((string) config('production.ocr.google.endpoint'), PHP_URL_HOST);
        if (! $this->enabled() || config('production.ocr.google.location') !== 'eu'
            || $endpointHost !== 'eu-documentai.googleapis.com') {
            throw new NonRetryableJobException('ocr_fallback_unavailable');
        }
        $quota = (int) config('production.ocr.google.monthly_page_quota');
        $budget = (int) config('production.ocr.google.monthly_budget_minor');
        $pageCost = (int) config('production.ocr.google.page_cost_minor');
        if ($quota < 1 || $budget < 1 || $pageCost < 1) {
            throw new NonRetryableJobException('ocr_fallback_budget_unavailable');
        }
        $maximumPages = min($quota, intdiv($budget, $pageCost));

        $period = now()->utc()->format('Ym');
        $usageKey = 'ocr:google:pages:'.$period;
        $used = (int) Cache::get($usageKey, 0);
        if ($used >= $maximumPages) {
            throw new NonRetryableJobException('ocr_fallback_quota_exhausted');
        }
        $lock = Cache::lock('ocr:google:concurrency', (int) config('production.ocr.google.timeout_seconds', 30) + 5);
        if (! $lock->get()) {
            throw new RetryableJobException('ocr_fallback_concurrency_saturated');
        }

        try {
            $token = $this->accessToken();
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout((int) config('production.ocr.google.timeout_seconds', 30))
                ->retry(
                    max(0, (int) config('production.ocr.google.attempts', 2) - 1),
                    250,
                    fn (Throwable $exception): bool => $exception instanceof ConnectionException,
                    throw: false,
                )->post($this->endpoint(), [
                    'rawDocument' => [
                        'content' => base64_encode($canonicalBytes),
                        'mimeType' => 'image/png',
                    ],
                    'skipHumanReview' => true,
                ]);
            if ($response->status() === 429 || $response->serverError()) {
                throw new RetryableJobException('ocr_fallback_temporary_failure');
            }
            if (! $response->successful()) {
                throw new NonRetryableJobException('ocr_fallback_rejected');
            }
            Cache::put($usageKey, $used + 1, now()->utc()->addMonths(2));

            return $this->result($response->json());
        } catch (NonRetryableJobException|RetryableJobException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new RetryableJobException('ocr_fallback_temporary_failure');
        } finally {
            $lock->release();
        }
    }

    private function endpoint(): string
    {
        $base = rtrim((string) config('production.ocr.google.endpoint'), '/');
        $project = rawurlencode((string) config('production.ocr.google.project_id'));
        $processor = rawurlencode((string) config('production.ocr.google.processor_id'));
        $version = rawurlencode((string) config('production.ocr.google.model_version'));

        return "{$base}/v1/projects/{$project}/locations/eu/processors/{$processor}/processorVersions/{$version}:process";
    }

    private function accessToken(): string
    {
        $path = (string) config('production.ocr.google.credentials_path');
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new NonRetryableJobException('ocr_fallback_credentials_unavailable');
        }
        $credentials = json_decode((string) file_get_contents($path), true);
        if (! is_array($credentials)
            || ! is_string($credentials['client_email'] ?? null)
            || ! is_string($credentials['private_key'] ?? null)) {
            throw new NonRetryableJobException('ocr_fallback_credentials_invalid');
        }
        $cacheKey = 'ocr:google:token:'.hash('sha256', $credentials['client_email']);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $now = time();
        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claim = $this->base64Url(json_encode([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/cloud-platform',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));
        $unsigned = $header.'.'.$claim;
        if (! openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new NonRetryableJobException('ocr_fallback_credentials_invalid');
        }
        $assertion = $unsigned.'.'.$this->base64Url($signature);
        $response = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]);
        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new RetryableJobException('ocr_fallback_authentication_failed');
        }
        Cache::put($cacheKey, $token, now()->addMinutes(50));

        return $token;
    }

    private function result(mixed $payload): OcrResult
    {
        if (! is_array($payload) || ! is_array($payload['document'] ?? null)) {
            throw new RetryableJobException('ocr_fallback_invalid_result');
        }
        $document = $payload['document'];
        $text = is_string($document['text'] ?? null) ? $document['text'] : '';
        if (strlen($text) > (int) config('production.ocr.max_output_bytes', 8_388_608)) {
            throw new NonRetryableJobException('ocr_output_too_large');
        }
        $lines = [];
        $warnings = [];
        foreach (($document['pages'] ?? []) as $page) {
            if (! is_array($page)) {
                continue;
            }
            foreach (($page['lines'] ?? []) as $line) {
                $layout = is_array($line) ? ($line['layout'] ?? null) : null;
                if (! is_array($layout)) {
                    continue;
                }
                $lineText = $this->anchorText($text, $layout['textAnchor']['textSegments'] ?? []);
                $confidence = is_numeric($layout['confidence'] ?? null) ? (float) $layout['confidence'] : null;
                $category = match (true) {
                    $confidence === null => 'unavailable',
                    $confidence >= 0.9 => 'reliable',
                    $confidence >= 0.7 => 'uncertain',
                    default => 'unreliable',
                };
                if ($category !== 'reliable') {
                    $warnings[] = 'low_confidence_text';
                }
                $lines[] = new OcrTextLine(trim($lineText), $category, false, $confidence);
            }
        }

        return new OcrResult(trim($text), $lines, array_values(array_unique($warnings)), self::PROVIDER,
            (string) config('production.ocr.google.model_version'), 'eng');
    }

    private function anchorText(string $text, mixed $segments): string
    {
        $result = '';
        foreach (is_array($segments) ? $segments : [] as $segment) {
            $start = (int) ($segment['startIndex'] ?? 0);
            $end = (int) ($segment['endIndex'] ?? 0);
            if ($start >= 0 && $end >= $start && $end <= strlen($text)) {
                $result .= substr($text, $start, $end - $start);
            }
        }

        return $result;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
