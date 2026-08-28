<?php

namespace Tests\Feature\Recipes;

use App\Integrations\RecipeWebpages\AddressClassifier;
use App\Integrations\RecipeWebpages\AddressResolver;
use App\Integrations\RecipeWebpages\DecodedHtmlBuffer;
use App\Integrations\RecipeWebpages\ValidatedDestination;
use App\Integrations\RecipeWebpages\WebpageFetcher;
use App\Integrations\RecipeWebpages\WebpageFetchException;
use App\Integrations\RecipeWebpages\WebpageResponse;
use App\Integrations\RecipeWebpages\WebpageTransport;
use App\Integrations\RecipeWebpages\WebpageUrlValidator;
use App\Observability\OperationalTelemetry;
use Illuminate\Support\Facades\Log;
use Psr\Log\AbstractLogger;
use Stringable;
use Tests\TestCase;

class WebpageImportSecurityTest extends TestCase
{
    public function test_url_validation_explicitly_allows_only_http_https_standard_ports_without_credentials(): void
    {
        $validator = new WebpageUrlValidator;
        $this->assertSame(80, $validator->validate('http://example.com/recipe')->port);
        $this->assertSame(443, $validator->validate('https://example.com/recipe')->port);

        foreach ([
            'file:///etc/passwd', 'ftp://example.com/recipe', 'javascript:alert(1)',
            'https://user:secret@example.com/recipe', 'http://example.com:8080/recipe',
            'http://localhost/recipe', 'http://sub.localhost/recipe',
        ] as $url) {
            try {
                $validator->validate($url);
                $this->fail('Unsafe URL was accepted: '.$url);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_address_classifier_rejects_private_reserved_mapped_and_metadata_ranges(): void
    {
        $classifier = new AddressClassifier;
        foreach ([
            '127.0.0.1', '0.0.0.0', '10.0.0.1', '172.16.0.1', '192.168.1.1',
            '169.254.169.254', '100.64.0.1', '::1', 'fc00::1', 'fe80::1',
            '::ffff:127.0.0.1', '::ffff:192.168.1.1', '224.0.0.1', 'ff02::1',
        ] as $address) {
            $this->assertFalse($classifier->isPublic($address), $address);
        }
        $this->assertTrue($classifier->isPublic('93.184.216.34'));
        $this->assertTrue($classifier->isPublic('2606:4700:4700::1111'));
    }

    public function test_mixed_dns_answer_is_denied_before_transport_and_validated_address_is_pinned(): void
    {
        $resolver = new FakeAddressResolver(['recipes.test' => ['93.184.216.34', '10.0.0.2']]);
        $transport = new FakeWebpageTransport([new WebpageResponse(200, ['content-type' => 'text/html'], '<html></html>')]);
        $fetcher = $this->fetcher($resolver, $transport);

        try {
            $fetcher->fetch('https://recipes.test/a', '01K1SAFEFETCHCORRELATION00000');
            $this->fail('Mixed public/private DNS should be denied.');
        } catch (WebpageFetchException $exception) {
            $this->assertSame('non_public_destination', $exception->safeErrorCode);
        }
        $this->assertCount(0, $transport->destinations);

        $resolver->answers['recipes.test'] = ['93.184.216.34'];
        $fetcher->fetch('https://recipes.test/a', '01K1SAFEFETCHCORRELATION00000');
        $this->assertSame('93.184.216.34', $transport->destinations[0]->address);
    }

    public function test_every_relative_redirect_hop_is_revalidated_and_private_redirect_is_not_requested(): void
    {
        $resolver = new FakeAddressResolver([
            'recipes.test' => ['93.184.216.34'],
            'private.test' => ['192.168.1.2'],
        ]);
        $transport = new FakeWebpageTransport([
            new WebpageResponse(302, ['location' => '/next'], ''),
            new WebpageResponse(302, ['location' => 'https://private.test/secret'], ''),
        ]);
        $fetcher = $this->fetcher($resolver, $transport);

        try {
            $fetcher->fetch('https://recipes.test/start', '01K1REDIRECTCORRELATION000000');
            $this->fail('Private redirect should be denied.');
        } catch (WebpageFetchException $exception) {
            $this->assertSame('non_public_destination', $exception->safeErrorCode);
        }

        $this->assertCount(2, $transport->destinations);
        $this->assertSame('https://recipes.test/next', $transport->destinations[1]->url->value);
        $this->assertSame(['recipes.test', 'recipes.test', 'private.test'], $resolver->queries);
    }

    public function test_redirect_loop_and_unsupported_content_type_fail_safely(): void
    {
        $resolver = new FakeAddressResolver(['recipes.test' => ['93.184.216.34']]);
        $loop = new FakeWebpageTransport([
            new WebpageResponse(302, ['location' => '/b'], ''),
            new WebpageResponse(302, ['location' => '/a'], ''),
        ]);

        $this->expectSafeCode(fn () => $this->fetcher($resolver, $loop)->fetch('https://recipes.test/a', '01K1LOOPCORRELATION0000000000'), 'redirect_loop');

        $binary = new FakeWebpageTransport([
            new WebpageResponse(200, ['content-type' => 'application/pdf'], '%PDF'),
        ]);
        $this->expectSafeCode(fn () => $this->fetcher($resolver, $binary)->fetch('https://recipes.test/file', '01K1TYPECORRELATION0000000000'), 'unsupported_content_type');
    }

    public function test_safe_public_redirects_succeed_and_record_the_final_url(): void
    {
        $resolver = new FakeAddressResolver([
            'recipes.test' => ['93.184.216.34'],
            'cdn.recipes.test' => ['93.184.216.35'],
        ]);
        $transport = new FakeWebpageTransport([
            new WebpageResponse(302, ['location' => '/next'], ''),
            new WebpageResponse(302, ['location' => 'https://cdn.recipes.test/final'], ''),
            new WebpageResponse(200, ['content-type' => 'application/xhtml+xml'], '<html></html>'),
        ]);

        $result = $this->fetcher($resolver, $transport)
            ->fetch('https://recipes.test/start', '01K1SAFEREDIRECT000000000000');

        $this->assertSame('https://cdn.recipes.test/final', $result->finalUrl);
        $this->assertSame(2, $result->redirectCount);
        $this->assertSame(
            ['https://recipes.test/start', 'https://recipes.test/next', 'https://cdn.recipes.test/final'],
            array_map(fn (ValidatedDestination $destination): string => $destination->url->value, $transport->destinations),
        );
    }

    public function test_redirects_to_unsupported_destinations_are_rejected_before_a_second_request(): void
    {
        foreach ([
            'http://localhost/secret',
            'https://example.com:8080/secret',
            'file:///etc/passwd',
        ] as $location) {
            $resolver = new FakeAddressResolver(['recipes.test' => ['93.184.216.34']]);
            $transport = new FakeWebpageTransport([
                new WebpageResponse(302, ['location' => $location], ''),
            ]);

            $this->expectSafeCode(
                fn () => $this->fetcher($resolver, $transport)
                    ->fetch('https://recipes.test/start', '01K1BADREDIRECT0000000000000'),
                'invalid_redirect',
            );
            $this->assertCount(1, $transport->destinations);
        }
    }

    public function test_redirect_limit_is_enforced(): void
    {
        config()->set('production.imports.max_redirects', 1);
        $resolver = new FakeAddressResolver(['recipes.test' => ['93.184.216.34']]);
        $transport = new FakeWebpageTransport([
            new WebpageResponse(302, ['location' => '/two'], ''),
            new WebpageResponse(302, ['location' => '/three'], ''),
        ]);

        $this->expectSafeCode(
            fn () => $this->fetcher($resolver, $transport)
                ->fetch('https://recipes.test/one', '01K1REDIRECTLIMIT00000000000'),
            'too_many_redirects',
        );
        $this->assertCount(2, $transport->destinations);
    }

    public function test_dns_is_revalidated_at_each_hop_and_rebinding_cannot_change_the_pinned_connection(): void
    {
        $resolver = new RebindingAddressResolver([
            ['93.184.216.34'],
            ['10.0.0.9'],
        ]);
        $transport = new FakeWebpageTransport([
            new WebpageResponse(302, ['location' => '/next'], ''),
        ]);

        $this->expectSafeCode(
            fn () => $this->fetcher($resolver, $transport)
                ->fetch('https://recipes.test/start', '01K1REBINDING00000000000000'),
            'non_public_destination',
        );
        $this->assertSame('93.184.216.34', $transport->destinations[0]->address);
        $this->assertCount(1, $transport->destinations);
        $this->assertSame(2, $resolver->queries);
    }

    public function test_pdf_image_binary_and_json_content_types_are_rejected(): void
    {
        foreach (['application/pdf', 'image/png', 'application/octet-stream', 'application/json'] as $contentType) {
            $resolver = new FakeAddressResolver(['recipes.test' => ['93.184.216.34']]);
            $transport = new FakeWebpageTransport([
                new WebpageResponse(200, ['content-type' => $contentType], 'unsupported'),
            ]);
            $this->expectSafeCode(
                fn () => $this->fetcher($resolver, $transport)
                    ->fetch('https://recipes.test/source', '01K1CONTENTTYPE000000000000'),
                'unsupported_content_type',
            );
        }
    }

    public function test_timeout_is_retryable_and_telemetry_contains_only_safe_low_cardinality_context(): void
    {
        $logger = new WebpageTelemetryRecordingLogger;
        Log::swap($logger);
        $secret = 'private-query-secret';
        $resolver = new FakeAddressResolver(['recipes.test' => ['93.184.216.34']]);

        try {
            $this->fetcher($resolver, new FailingWebpageTransport(
                new WebpageFetchException('fetch_timeout', 'timeout', true),
            ))->fetch('https://recipes.test/source?token='.$secret, '01K1TIMEOUTCORRELATION000000');
            $this->fail('Timeout should fail.');
        } catch (WebpageFetchException $exception) {
            $this->assertTrue($exception->retryable);
            $this->assertSame('fetch_timeout', $exception->safeErrorCode);
        }

        $encoded = json_encode($logger->records, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('provider.request', $encoded);
        $this->assertStringContainsString('timeout', $encoded);
        $this->assertStringContainsString('01K1TIMEOUTCORRELATION000000', $encoded);
        $this->assertStringNotContainsString($secret, $encoded);
        $this->assertStringNotContainsString('recipes.test', $encoded);
    }

    public function test_content_length_and_actual_decoded_stream_are_both_bounded(): void
    {
        $early = new DecodedHtmlBuffer(10);
        $this->assertSame(0, $early->header("Content-Length: 11\r\n"));
        $this->assertTrue($early->oversized);
        $this->assertSame('', $early->body);

        $streamed = new DecodedHtmlBuffer(10);
        $this->assertSame(6, $streamed->append('123456'));
        $this->assertSame(0, $streamed->append('78901'));
        $this->assertTrue($streamed->oversized);
        $this->assertSame('123456', $streamed->body);

        $decoded = new DecodedHtmlBuffer(10);
        $this->assertSame(10, $decoded->append('decoded123'));
        $this->assertFalse($decoded->oversized);
    }

    private function fetcher(AddressResolver $resolver, WebpageTransport $transport): WebpageFetcher
    {
        return new WebpageFetcher(
            new WebpageUrlValidator,
            $resolver,
            new AddressClassifier,
            $transport,
            app(OperationalTelemetry::class),
        );
    }

    private function expectSafeCode(callable $operation, string $code): void
    {
        try {
            $operation();
            $this->fail('Expected safe failure '.$code);
        } catch (WebpageFetchException $exception) {
            $this->assertSame($code, $exception->safeErrorCode);
        }
    }
}

final class FakeAddressResolver implements AddressResolver
{
    /** @var list<string> */
    public array $queries = [];

    /** @param array<string, list<string>> $answers */
    public function __construct(public array $answers) {}

    public function resolve(string $host): array
    {
        $this->queries[] = $host;

        return $this->answers[$host] ?? [];
    }
}

final class FakeWebpageTransport implements WebpageTransport
{
    /** @var list<ValidatedDestination> */
    public array $destinations = [];

    /** @param list<WebpageResponse> $responses */
    public function __construct(private array $responses) {}

    public function request(ValidatedDestination $destination): WebpageResponse
    {
        $this->destinations[] = $destination;

        return array_shift($this->responses) ?? throw new \RuntimeException('Unexpected request.');
    }
}

final class RebindingAddressResolver implements AddressResolver
{
    public int $queries = 0;

    /** @param list<list<string>> $answers */
    public function __construct(private array $answers) {}

    public function resolve(string $host): array
    {
        $this->queries++;

        return array_shift($this->answers) ?? [];
    }
}

final readonly class FailingWebpageTransport implements WebpageTransport
{
    public function __construct(private WebpageFetchException $exception) {}

    public function request(ValidatedDestination $destination): WebpageResponse
    {
        throw $this->exception;
    }
}

final class WebpageTelemetryRecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array}> */
    public array $records = [];

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
