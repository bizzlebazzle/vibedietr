<?php

namespace Tests\Feature\Integrations;

use App\Integrations\OpenFoodFacts\OpenFoodFactsClient;
use App\Integrations\OpenFoodFacts\OpenFoodFactsLookupStatus;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OpenFoodFactsClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.openfoodfacts', [
            'base_url' => 'https://world.openfoodfacts.test',
            'api_version' => 'v3.4',
            'user_agent' => 'VibeDietr/1.2.3 (https://vibedietr.test)',
            'connect_timeout' => 1.25,
            'timeout' => 4.5,
            'attempts' => 2,
            'backoff_ms' => [0],
            'max_retry_after' => 0,
        ]);

        Http::preventStrayRequests();
    }

    public function test_success_uses_configured_endpoint_identification_and_maps_product_data(): void
    {
        Http::fake(['*' => Http::response($this->validResponse([
            'quantity' => '2 x 125 g',
            'serving_quantity' => '25',
            'serving_size' => '25 g',
            'nutriments' => [
                'energy-kcal_100g' => '123.4567890123456789',
                'energy-kj_100g' => '999.25',
                'proteins_100g' => '0',
                'fat_serving' => '2.5',
                'unknown_100g' => '10',
            ],
        ]))]);

        $result = app(OpenFoodFactsClient::class)->lookup('0123456789012');

        $this->assertSame(OpenFoodFactsLookupStatus::Success, $result->status);
        $this->assertNotNull($result->product);
        $this->assertSame('0123456789012', $result->product->code);
        $this->assertSame('Mapped product', $result->product->name);
        $this->assertSame(['test'], $result->product->keywords);
        $this->assertSame(['en:test'], $result->product->categories);
        $this->assertSame(2, $result->product->quantity);
        $this->assertNull($result->product->quantityUnit);
        $this->assertTrue($result->product->multipleQuantity);
        $this->assertSame(25, $result->product->servingQuantity);
        $this->assertSame('g', $result->product->servingQuantityUnit);
        $this->assertSame('https://images.example/product.jpg', $result->product->imageUrl);
        $this->assertSame('123.4567890123456789', data_get($result->product->nutriments, 'per_100g.energy_kcal'));
        $this->assertSame('999.25', data_get($result->product->nutriments, 'per_100g.energy_kj'));
        $this->assertSame('0', data_get($result->product->nutriments, 'per_100g.protein'));
        $this->assertSame('2.5', data_get($result->product->nutriments, 'per_serving.fat'));
        $this->assertArrayNotHasKey('unknown', $result->product->nutriments['per_100g']);

        Http::assertSent(function (Request $request): bool {
            return str_starts_with(
                $request->url(),
                'https://world.openfoodfacts.test/api/v3.4/product/0123456789012.json?',
            )
                && $request->hasHeader('Accept', 'application/json')
                && $request->hasHeader('User-Agent', 'VibeDietr/1.2.3 (https://vibedietr.test)')
                && str_contains($request->url(), 'fields=');
        });
    }

    #[DataProvider('invalidConfigurationProvider')]
    public function test_required_identification_and_timeout_configuration_fail_before_a_request(
        string $key,
        mixed $value,
    ): void {
        config()->set("services.openfoodfacts.{$key}", $value);
        Http::fake();

        $result = app(OpenFoodFactsClient::class)->lookup('1234567890123');

        $this->assertSame(OpenFoodFactsLookupStatus::PermanentFailure, $result->status);
        Http::assertNothingSent();
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidConfigurationProvider(): array
    {
        return [
            'application identification' => ['user_agent', ''],
            'base url' => ['base_url', ''],
            'api version' => ['api_version', ''],
            'connection timeout' => ['connect_timeout', 0],
            'request timeout' => ['timeout', 0],
            'attempt bound' => ['attempts', 0],
        ];
    }

    public function test_connection_failure_retries_only_to_the_configured_bound(): void
    {
        Http::fake(['*' => Http::failedConnection('private technical detail')]);

        $result = app(OpenFoodFactsClient::class)->lookup('1234567890123');

        $this->assertSame(OpenFoodFactsLookupStatus::Unavailable, $result->status);
        Http::assertSentCount(2);
    }

    public function test_request_timeout_is_returned_as_safe_unavailability(): void
    {
        Http::fake(['*' => Http::failedConnection('cURL error 28: operation timed out at internal-host')]);

        $result = app(OpenFoodFactsClient::class)->lookup('1234567890123');

        $this->assertSame(OpenFoodFactsLookupStatus::Unavailable, $result->status);
        $this->assertNull($result->product);
        Http::assertSentCount(2);
    }

    public function test_retryable_server_failure_can_recover(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['safe' => 'ignored'], 500)
            ->push($this->validResponse(['code' => '1234567890123']))]);

        $result = app(OpenFoodFactsClient::class)->lookup('1234567890123');

        $this->assertSame(OpenFoodFactsLookupStatus::Success, $result->status);
        Http::assertSentCount(2);
    }

    #[DataProvider('permanentStatusProvider')]
    public function test_permanent_client_failures_are_not_retried(int $status): void
    {
        Http::fake(['*' => Http::response(['technical' => 'must not reach UI'], $status)]);

        $result = app(OpenFoodFactsClient::class)->lookup('1234567890123');

        $this->assertSame(OpenFoodFactsLookupStatus::PermanentFailure, $result->status);
        Http::assertSentCount(1);
    }

    /** @return array<string, array{int}> */
    public static function permanentStatusProvider(): array
    {
        return ['bad request' => [400], 'unauthorized' => [401], 'forbidden' => [403]];
    }

    public function test_rate_limit_without_safe_retry_after_is_not_retried(): void
    {
        Http::fake(['*' => Http::response(['technical' => 'ignored'], 429, ['Retry-After' => '60'])]);

        $result = app(OpenFoodFactsClient::class)->lookup('1234567890123');

        $this->assertSame(OpenFoodFactsLookupStatus::RateLimited, $result->status);
        Http::assertSentCount(1);
    }

    public function test_safe_retry_after_is_honoured_without_slowing_the_test(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['technical' => 'ignored'], 429, ['Retry-After' => '0'])
            ->push($this->validResponse(['code' => '1234567890123']))]);

        $result = app(OpenFoodFactsClient::class)->lookup('1234567890123');

        $this->assertSame(OpenFoodFactsLookupStatus::Success, $result->status);
        Http::assertSentCount(2);
    }

    public function test_documented_global_limit_503_is_rate_limited(): void
    {
        Http::fake(['*' => Http::response([], 503)]);

        $result = app(OpenFoodFactsClient::class)->lookup('1234567890123');

        $this->assertSame(OpenFoodFactsLookupStatus::RateLimited, $result->status);
        Http::assertSentCount(1);
    }

    public function test_missing_product_is_distinct_and_not_retried(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'failure',
            'result' => ['id' => 'product_not_found'],
        ], 404)]);

        $result = app(OpenFoodFactsClient::class)->lookup('1234567890123');

        $this->assertSame(OpenFoodFactsLookupStatus::NotFound, $result->status);
        Http::assertSentCount(1);
    }

    public function test_malformed_json_is_an_invalid_response(): void
    {
        Http::fake(['*' => Http::response('{not-json', 200, ['Content-Type' => 'application/json'])]);

        $result = app(OpenFoodFactsClient::class)->lookup('1234567890123');

        $this->assertSame(OpenFoodFactsLookupStatus::InvalidResponse, $result->status);
        Http::assertSentCount(1);
    }

    public function test_provider_barcode_must_match_the_normalized_lookup_input(): void
    {
        Http::fake(['*' => Http::response($this->validResponse())]);

        $result = app(OpenFoodFactsClient::class)->lookup(' 1234567890123 ');

        $this->assertSame(OpenFoodFactsLookupStatus::InvalidResponse, $result->status);
        $this->assertNull($result->product);
    }

    #[DataProvider('invalidPayloadProvider')]
    public function test_invalid_success_payloads_are_rejected(array $payload): void
    {
        Http::fake(['*' => Http::response($payload)]);

        $result = app(OpenFoodFactsClient::class)->lookup('1234567890123');

        $this->assertSame(OpenFoodFactsLookupStatus::InvalidResponse, $result->status);
        Http::assertSentCount(1);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidPayloadProvider(): array
    {
        $base = [
            'status' => 'success',
            'result' => ['id' => 'product_found'],
            'product' => ['code' => '1234567890123', 'nutriments' => []],
        ];

        return [
            'missing product' => [[...$base, 'product' => null]],
            'unexpected status' => [[...$base, 'status' => 'failure']],
            'missing code' => [[...$base, 'product' => ['nutriments' => []]]],
            'missing nutriments' => [[...$base, 'product' => ['code' => '1234567890123']]],
            'scalar nutriments' => [[...$base, 'product' => ['code' => '1234567890123', 'nutriments' => 'bad']]],
            'invalid supported nutrient' => [[...$base, 'product' => [
                'code' => '1234567890123',
                'nutriments' => ['fat_100g' => ['bad']],
            ]]],
            'invalid list type' => [[...$base, 'product' => [
                'code' => '1234567890123',
                'keywords' => 'bad',
                'nutriments' => [],
            ]]],
        ];
    }

    /** @param array<string, mixed> $productOverrides */
    private function validResponse(array $productOverrides = []): array
    {
        return [
            'status' => 'success',
            'result' => ['id' => 'product_found'],
            'errors' => [],
            'warnings' => [],
            'product' => array_replace([
                'code' => '0123456789012',
                'product_name' => 'Mapped product',
                'keywords' => ['test'],
                'categories_tags' => ['en:test'],
                'nutriments' => ['fat_100g' => '1.25'],
                'image_front_url' => 'https://images.example/product.jpg',
            ], $productOverrides),
        ];
    }
}
