<?php

namespace Tests\Feature\Ingredients;

use App\Livewire\Ingredients\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OpenFoodFactsLivewireTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{int, string}> */
    public static function lookupFailures(): iterable
    {
        yield 'not found' => [404, 'No product found for that barcode.'];
        yield 'temporary failure' => [500, 'Food database temporarily unavailable.'];
        yield 'rate limit' => [429, 'Too many lookups.'];
        yield 'invalid response' => [200, 'Product data could not be read.'];
    }

    public function test_user_can_follow_manual_recovery_guidance_after_failed_lookup(): void
    {
        Http::fake(['*' => Http::response('unavailable', 500)]);
        $owner = User::factory()->create();
        Livewire::actingAs($owner)->test(Form::class)
            ->set('name', 'Manual recovery food')
            ->set('quantity', '100')->set('quantity_unit', 'g')
            ->set('per_100g_protein', '7.5')
            ->set('barcode', '1234567890123')->call('fetchFromOff')
            ->assertSee('To continue manually, clear the barcode')
            ->set('barcode', '')->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('ingredients', [
            'user_id' => $owner->id, 'name' => 'Manual recovery food', 'barcode' => null,
        ]);
    }

    #[DataProvider('lookupFailures')]
    public function test_failed_lookup_keeps_user_fields_and_presents_retry_and_manual_recovery(int $status, string $message): void
    {
        Http::fakeSequence()->push('private provider details', $status)->push($this->validResponse());
        $component = Livewire::actingAs(User::factory()->create())->test(Form::class)
            ->set('name', 'User-entered food')
            ->set('quantity', '100')
            ->set('quantity_unit', 'g')
            ->set('per_100g_protein', '7.5')
            ->set('barcode', '0123456789012')
            ->call('fetchFromOff')
            ->assertSee($message)
            ->assertSee('Your entered fields have been kept.')
            ->assertSee('use Fetch from OFF to try again when available')
            ->assertSee('To continue manually, clear the barcode')
            ->assertDontSee('private provider details')
            ->assertSet('name', 'User-entered food')
            ->assertSet('quantity', '100')
            ->assertSet('per_100g_protein', '7.5');

        $component->call('fetchFromOff')->assertSet('lookupFailure', null)
            ->assertDontSee('Your entered fields have been kept.');
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.openfoodfacts.attempts', 1);
        config()->set('services.openfoodfacts.backoff_ms', [0]);
        Http::preventStrayRequests();
    }

    public function test_success_preserves_the_existing_form_population_behavior(): void
    {
        Http::fake(['*' => Http::response($this->validResponse())]);

        Livewire::actingAs(User::factory()->create())->test(Form::class)
            ->set('barcode', '0123456789012')
            ->call('fetchFromOff')
            ->assertSet('barcode', '0123456789012')
            ->assertSet('name', 'Mapped product')
            ->assertSet('keywords', ['one'])
            ->assertSet('categories', ['en:test'])
            ->assertSet('quantity', 250)
            ->assertSet('quantity_unit', 'g')
            ->assertSet('serving_quantity', 25)
            ->assertSet('serving_quantity_unit', 'g')
            ->assertSet('per_100g_protein', '0')
            ->assertSet('image_url', 'https://images.example/front.jpg')
            ->assertDispatched(
                'notify',
                type: 'success',
                message: 'Successfully loaded item information from OpenFoodFacts.',
            );
    }

    public function test_not_found_has_a_distinct_safe_message(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'failure',
            'result' => ['id' => 'product_not_found'],
        ], 404)]);

        $this->lookup()->assertDispatched(
            'notify',
            type: 'error',
            message: 'No product found for that barcode.',
        );
    }

    public function test_unavailable_provider_has_an_actionable_message(): void
    {
        Http::fake(['*' => Http::response('private provider failure', 500)]);

        $this->lookup()
            ->assertDispatched(
                'notify',
                type: 'error',
                message: 'Food database temporarily unavailable. Please try again.',
            )
            ->assertDontSee('private provider failure');
    }

    public function test_rate_limit_has_an_actionable_message(): void
    {
        Http::fake(['*' => Http::response('private headers and body', 429, ['Retry-After' => '60'])]);

        $this->lookup()
            ->assertDispatched(
                'notify',
                type: 'error',
                message: 'Too many lookups. Please try again shortly.',
            )
            ->assertDontSee('private headers and body');
    }

    public function test_malformed_response_does_not_render_provider_details(): void
    {
        Http::fake(['*' => Http::response('{internal-host: secret exception detail', 200)]);

        $this->lookup()
            ->assertDispatched(
                'notify',
                type: 'error',
                message: 'Product data could not be read.',
            )
            ->assertDontSee('internal-host')
            ->assertDontSee('secret exception detail');
    }

    public function test_local_barcode_throttle_prevents_upstream_call_after_boundary(): void
    {
        config(['security.throttles.barcode_user.attempts' => 1]);
        Http::fake(['*' => Http::response($this->validResponse())]);
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)->test(Form::class)
            ->set('barcode', '0123456789012')
            ->call('fetchFromOff')
            ->assertStatus(200);

        $component->call('fetchFromOff')->assertStatus(429);
        Http::assertSentCount(1);
    }

    private function lookup(): Testable
    {
        return Livewire::actingAs(User::factory()->create())->test(Form::class)
            ->set('barcode', '1234567890123')
            ->call('fetchFromOff');
    }

    /** @return array<string, mixed> */
    private function validResponse(): array
    {
        return [
            'status' => 'success',
            'result' => ['id' => 'product_found'],
            'product' => [
                'code' => '0123456789012',
                'product_name' => 'Mapped product',
                'keywords' => ['one'],
                'categories_tags' => ['en:test'],
                'quantity' => '250 g',
                'serving_quantity' => '25',
                'serving_size' => '25 g',
                'nutriments' => ['proteins_100g' => '0'],
                'image_front_url' => 'https://images.example/front.jpg',
            ],
        ];
    }
}
