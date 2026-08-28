<?php

namespace App\Integrations\RecipeWebpages;

use App\Observability\OperationalTelemetry;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

final class WebpageFetcher
{
    public const PROVIDER = 'recipe_webpage';

    public function __construct(
        private readonly WebpageUrlValidator $validator,
        private readonly AddressResolver $resolver,
        private readonly AddressClassifier $classifier,
        private readonly WebpageTransport $transport,
        private readonly OperationalTelemetry $telemetry,
    ) {}

    public function fetch(string $submittedUrl, string $correlationId): FetchedWebpage
    {
        $started = microtime(true);
        $current = $this->validator->validate($submittedUrl);
        $visited = [];

        try {
            for ($redirects = 0; ; $redirects++) {
                if (isset($visited[$current->value])) {
                    throw new WebpageFetchException('redirect_loop', 'redirect');
                }
                $visited[$current->value] = true;

                $addresses = $this->resolver->resolve($current->host);
                if ($addresses === []) {
                    throw new WebpageFetchException('dns_resolution_failed', 'network', true);
                }
                foreach ($addresses as $address) {
                    if (! $this->classifier->isPublic($address)) {
                        $this->telemetry->counter('recipe_webpage.ssrf_denied', [
                            'provider' => self::PROVIDER,
                            'outcome' => 'blocked',
                            'failure_category' => 'non_public_destination',
                        ]);
                        throw new WebpageFetchException('non_public_destination', 'ssrf');
                    }
                }
                sort($addresses, SORT_STRING);

                $response = $this->transport->request(new ValidatedDestination($current, $addresses[0]));
                if ($response->status >= 300 && $response->status < 400) {
                    if ($redirects >= (int) config('production.imports.max_redirects', 5)) {
                        throw new WebpageFetchException('too_many_redirects', 'redirect');
                    }
                    $location = $response->header('location');
                    if ($location === null || trim($location) === '') {
                        throw new WebpageFetchException('invalid_redirect', 'redirect');
                    }
                    try {
                        $resolved = UriResolver::resolve(new Uri($current->value), new Uri($location));
                        $current = $this->validator->validate((string) $resolved);
                    } catch (\Throwable) {
                        throw new WebpageFetchException('invalid_redirect', 'redirect');
                    }

                    continue;
                }

                if (in_array($response->status, [408, 429], true) || $response->status >= 500) {
                    throw new WebpageFetchException('remote_temporarily_unavailable', 'http', true);
                }
                if ($response->status < 200 || $response->status >= 300) {
                    throw new WebpageFetchException('page_not_publicly_available', 'unsupported');
                }

                $contentType = strtolower(trim(explode(';', $response->header('content-type') ?? '')[0]));
                if (! in_array($contentType, ['text/html', 'application/xhtml+xml'], true)) {
                    throw new WebpageFetchException('unsupported_content_type', 'unsupported');
                }

                $this->telemetry->counter('recipe_webpage.fetch', [
                    'provider' => self::PROVIDER,
                    'outcome' => 'success',
                ]);

                return new FetchedWebpage($submittedUrl, $current->value, $response->body, $redirects);
            }
        } catch (WebpageFetchException $exception) {
            $this->telemetry->counter('recipe_webpage.fetch', [
                'provider' => self::PROVIDER,
                'outcome' => 'failed',
                'failure_category' => $exception->failureCategory,
            ]);
            throw $exception;
        } finally {
            $this->telemetry->timing('provider.request', (microtime(true) - $started) * 1000, [
                'provider' => self::PROVIDER,
                'operation' => 'webpage.fetch',
                'correlation_id' => $correlationId,
            ]);
        }
    }
}
