<?php

namespace Tests\Feature\Ingredients;

use App\Domain\Ingredients\IngredientBarcodeProvenance;
use App\Livewire\Ingredients\Form;
use App\Models\Ingredient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IngredientBarcodeProvenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.openfoodfacts.attempts', 1);
        config()->set('services.openfoodfacts.backoff_ms', [0]);
        Http::preventStrayRequests();
    }

    public function test_manual_controller_create_ignores_machine_owned_fields(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('ingredients.store'), [
            'name' => 'Manual oats',
            'quantity' => 1,
            'quantity_unit' => 'g',
            'barcode' => '1234567890123',
            'barcode_source' => 'forged-provider',
            'barcode_imported_at' => '2020-01-01T00:00:00Z',
            'barcode_provenance' => IngredientBarcodeProvenance::MachineImported->value,
        ])->assertRedirect(route('ingredients.index'));

        $ingredient = Ingredient::query()->sole();

        $this->assertSame('Manual oats', $ingredient->name);
        $this->assertNull($ingredient->barcode);
        $this->assertSame(IngredientBarcodeProvenance::Manual, $ingredient->barcode_provenance);
        $this->assertNull($ingredient->barcode_source);
        $this->assertNull($ingredient->barcode_imported_at);
    }

    public function test_manual_livewire_create_ignores_a_forged_barcode(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(Form::class)
            ->set('name', 'Manual rice')
            ->set('barcode', '1234567890123')
            ->set('quantity', 2)
            ->set('quantity_unit', 'kg')
            ->call('save')
            ->assertHasNoErrors();

        $ingredient = Ingredient::query()->sole();

        $this->assertSame('Manual rice', $ingredient->name);
        $this->assertNull($ingredient->barcode);
        $this->assertSame(IngredientBarcodeProvenance::Manual, $ingredient->barcode_provenance);
        $this->assertNull($ingredient->barcode_source);
        $this->assertNull($ingredient->barcode_imported_at);
    }

    public function test_machine_fields_are_not_mass_assignable(): void
    {
        $ingredient = Ingredient::factory()->manual()->create();

        $ingredient->fill([
            'name' => 'Allowed rename',
            'barcode' => '1234567890123',
            'barcode_source' => 'forged-provider',
            'barcode_imported_at' => now()->subYear(),
            'barcode_provenance' => IngredientBarcodeProvenance::MachineImported,
        ])->save();

        $ingredient->refresh();

        $this->assertSame('Allowed rename', $ingredient->name);
        $this->assertNull($ingredient->barcode);
        $this->assertSame(IngredientBarcodeProvenance::Manual, $ingredient->barcode_provenance);
        $this->assertNull($ingredient->barcode_source);
        $this->assertNull($ingredient->barcode_imported_at);
    }

    public function test_manual_controller_update_preserves_verified_provenance(): void
    {
        $user = User::factory()->create();
        $ingredient = Ingredient::factory()->for($user)->barcodeImported()->create();
        $original = $this->provenanceValues($ingredient);

        $this->actingAs($user)->put(route('ingredients.update', $ingredient), [
            'name' => 'Legitimate rename',
            'quantity' => 5,
            'quantity_unit' => 'g',
            'barcode' => '9999999999999',
            'barcode_source' => 'forged-provider',
            'barcode_imported_at' => '2020-01-01T00:00:00Z',
            'barcode_provenance' => IngredientBarcodeProvenance::LegacyUnknown->value,
        ])->assertRedirect(route('ingredients.index'));

        $ingredient->refresh();

        $this->assertSame('Legitimate rename', $ingredient->name);
        $this->assertSame($original, $this->provenanceValues($ingredient));
    }

    public function test_manual_livewire_update_preserves_verified_provenance(): void
    {
        $user = User::factory()->create();
        $ingredient = Ingredient::factory()->for($user)->barcodeImported()->create();
        $original = $this->provenanceValues($ingredient);

        Livewire::actingAs($user)->test(Form::class, ['ingredient' => $ingredient])
            ->set('name', 'Livewire rename')
            ->set('barcode', '9999999999999')
            ->call('save')
            ->assertHasNoErrors();

        $ingredient->refresh();

        $this->assertSame('Livewire rename', $ingredient->name);
        $this->assertSame($original, $this->provenanceValues($ingredient));
    }

    #[DataProvider('machinePropertyProvider')]
    public function test_machine_metadata_is_not_public_livewire_state(string $property, mixed $value): void
    {
        $this->expectException(PublicPropertyNotFoundException::class);

        Livewire::actingAs(User::factory()->create())->test(Form::class)
            ->set($property, $value);
    }

    public static function machinePropertyProvider(): array
    {
        return [
            'source' => ['barcode_source', 'forged-provider'],
            'timestamp' => ['barcode_imported_at', '2020-01-01T00:00:00Z'],
            'provenance' => ['barcode_provenance', IngredientBarcodeProvenance::MachineImported->value],
        ];
    }

    public function test_pending_import_token_is_locked_livewire_state(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs(User::factory()->create())->test(Form::class)
            ->set('pendingImportToken', 'forged-token');
    }

    public function test_successful_lookup_persists_server_derived_provenance_and_mapped_nutrition(): void
    {
        Date::setTestNow('2026-08-13 12:34:56 UTC');
        Http::fake(['*' => Http::response($this->validResponse())]);
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(Form::class)
            ->set('barcode', '0123456789012')
            ->call('fetchFromOff')
            ->set('name', 'Browser-forged replacement')
            ->set('quantity', 1)
            ->set('quantity_unit', 'kg')
            ->set('per_100g_protein', '999')
            ->call('save')
            ->assertHasNoErrors();

        $ingredient = Ingredient::query()->sole();

        $this->assertSame('Mapped product', $ingredient->name);
        $this->assertSame('0123456789012', $ingredient->barcode);
        $this->assertSame('openfoodfacts', $ingredient->barcode_source);
        $this->assertSame(IngredientBarcodeProvenance::MachineImported, $ingredient->barcode_provenance);
        $this->assertSame('2026-08-13T12:34:56+00:00', $ingredient->barcode_imported_at?->toIso8601String());
        $this->assertSame('250.000', $ingredient->quantity);
        $this->assertSame('g', $ingredient->quantity_unit);
        $this->assertSame('12.500000000000000000', data_get($ingredient->nutriments, 'per_100g.protein'));
        $this->assertSame('418.400000000000000000', data_get($ingredient->nutriments, 'per_100g.energy_kj'));

        Date::setTestNow();
    }

    #[DataProvider('failedLookupProvider')]
    public function test_failed_lookup_cannot_create_verified_provenance(string $failure): void
    {
        $this->fakeFailure($failure);
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)->test(Form::class)
            ->set('barcode', '1234567890123')
            ->call('fetchFromOff')
            ->set('name', "Manual after {$failure}")
            ->set('quantity', 1)
            ->set('quantity_unit', 'g')
            ->call('save');

        $ingredient = Ingredient::query()->sole();

        $this->assertNull($ingredient->barcode);
        $this->assertSame(IngredientBarcodeProvenance::Manual, $ingredient->barcode_provenance);
        $this->assertNull($ingredient->barcode_source);
        $this->assertNull($ingredient->barcode_imported_at);
        $component->assertSet('barcode', null);
    }

    public static function failedLookupProvider(): array
    {
        return [
            'not found' => ['not_found'],
            'timeout' => ['timeout'],
            'rate limit' => ['rate_limit'],
            'server failure' => ['server_failure'],
            'permanent provider failure' => ['permanent_failure'],
            'malformed response' => ['malformed'],
        ];
    }

    public function test_locally_invalid_empty_barcode_cannot_create_provenance(): void
    {
        $user = User::factory()->create();
        Http::fake();

        Livewire::actingAs($user)->test(Form::class)
            ->set('barcode', '   ')
            ->call('fetchFromOff')
            ->assertHasErrors('barcode')
            ->set('name', 'Manual fallback')
            ->set('quantity', 1)
            ->set('quantity_unit', 'g')
            ->call('save');

        $ingredient = Ingredient::query()->sole();

        $this->assertNull($ingredient->barcode);
        $this->assertSame(IngredientBarcodeProvenance::Manual, $ingredient->barcode_provenance);
        $this->assertNull($ingredient->barcode_imported_at);
        Http::assertNothingSent();
    }

    public function test_failed_reimport_preserves_existing_verified_provenance(): void
    {
        $user = User::factory()->create();
        $ingredient = Ingredient::factory()->for($user)->barcodeImported()->create();
        $original = $this->provenanceValues($ingredient);
        Http::fake(['*' => Http::response([
            'status' => 'failure',
            'result' => ['id' => 'product_not_found'],
        ], 404)]);

        Livewire::actingAs($user)->test(Form::class, ['ingredient' => $ingredient])
            ->set('barcode', '9999999999999')
            ->call('fetchFromOff')
            ->set('name', 'Safe rename after failure')
            ->call('save');

        $ingredient->refresh();

        $this->assertSame('Safe rename after failure', $ingredient->name);
        $this->assertSame($original, $this->provenanceValues($ingredient));
    }

    public function test_failed_lookup_clears_an_earlier_successful_pending_import(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->validResponse())
            ->push([
                'status' => 'failure',
                'result' => ['id' => 'product_not_found'],
            ], 404)]);
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(Form::class)
            ->set('barcode', '0123456789012')
            ->call('fetchFromOff')
            ->set('barcode', '9999999999999')
            ->call('fetchFromOff')
            ->set('name', 'Manual after stale success')
            ->set('quantity', 1)
            ->set('quantity_unit', 'g')
            ->call('save');

        $ingredient = Ingredient::query()->sole();

        $this->assertNull($ingredient->barcode);
        $this->assertSame(IngredientBarcodeProvenance::Manual, $ingredient->barcode_provenance);
        $this->assertNull($ingredient->barcode_imported_at);
    }

    public function test_non_owner_cannot_create_pending_import_state_for_an_ingredient(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ingredient = Ingredient::factory()->for($owner)->barcodeImported()->create();
        Http::fake();

        Livewire::actingAs($other)->test(Form::class, ['ingredient' => $ingredient])
            ->set('barcode', '9999999999999')
            ->call('fetchFromOff')
            ->assertForbidden();

        $this->assertSame(
            IngredientBarcodeProvenance::MachineImported,
            $ingredient->refresh()->barcode_provenance,
        );
        Http::assertNothingSent();
    }

    public function test_legacy_barcode_record_remains_readable_and_is_not_verified(): void
    {
        $user = User::factory()->create();
        $ingredient = Ingredient::factory()
            ->for($user)
            ->legacyBarcode()
            ->withNutrition()
            ->create(['barcode' => '3210987654321']);

        $this->actingAs($user)
            ->get(route('ingredients.show', $ingredient))
            ->assertOk()
            ->assertSee('3210987654321');

        $ingredient->refresh();

        $this->assertSame(IngredientBarcodeProvenance::LegacyUnknown, $ingredient->barcode_provenance);
        $this->assertNull($ingredient->barcode_source);
        $this->assertNull($ingredient->barcode_imported_at);
        $this->assertSame(245, data_get($ingredient->nutriments, 'per_100g.energy_kcal'));
    }

    /** @return array{barcode: ?string, source: ?string, imported_at: ?string, provenance: string} */
    private function provenanceValues(Ingredient $ingredient): array
    {
        return [
            'barcode' => $ingredient->barcode,
            'source' => $ingredient->barcode_source,
            'imported_at' => $ingredient->barcode_imported_at?->toIso8601String(),
            'provenance' => $ingredient->barcode_provenance->value,
        ];
    }

    private function fakeFailure(string $failure): void
    {
        match ($failure) {
            'not_found' => Http::fake(['*' => Http::response([
                'status' => 'failure',
                'result' => ['id' => 'product_not_found'],
            ], 404)]),
            'timeout' => Http::fake(['*' => Http::failedConnection('cURL error 28: timeout')]),
            'rate_limit' => Http::fake(['*' => Http::response([], 429, ['Retry-After' => '60'])]),
            'server_failure' => Http::fake(['*' => Http::response([], 500)]),
            'permanent_failure' => Http::fake(['*' => Http::response([], 400)]),
            'malformed' => Http::fake(['*' => Http::response('{bad-json', 200)]),
            default => throw new \InvalidArgumentException("Unknown failure fixture: {$failure}"),
        };
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
                'keywords' => ['imported'],
                'categories_tags' => ['en:test'],
                'quantity' => '250 g',
                'serving_quantity' => '25',
                'serving_size' => '25 g',
                'nutriments' => [
                    'energy-kcal_100g' => '100',
                    'proteins_100g' => '12.5',
                ],
                'image_front_url' => 'https://images.example/product.jpg',
            ],
        ];
    }
}
