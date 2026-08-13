<?php

namespace Tests\Feature\Ingredients;

use App\Livewire\Ingredients\Form;
use App\Models\Ingredient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class FormTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_an_ingredient(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test(Form::class)
            ->set('name', 'Test Ingredient')
            ->set('quantity', 1)
            ->set('quantity_unit', 'g')
            ->call('save')
            ->assertHasNoErrors();

        $ingredient = Ingredient::query()->first();

        $this->assertNotNull($ingredient);
        $this->assertSame($user->id, $ingredient->user_id);
        $this->assertSame('Test Ingredient', $ingredient->name);
        $this->assertSame('1.000', $ingredient->quantity);
        $this->assertSame('g', $ingredient->quantity_unit);
    }

    public function test_nutrition_values_preserve_storage_precision_when_saving(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test(Form::class)
            ->set('name', 'Rounded Nutrition Ingredient')
            ->set('quantity', 1)
            ->set('quantity_unit', 'g')
            ->set('per_100g_energy_kcal', 123.6)
            ->set('per_100g_fat', 1.236)
            ->set('per_100g_saturated_fat', 0.994)
            ->set('per_serving_sugars', 10.555)
            ->set('per_serving_salt', 0.004)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('per_100g_energy_kcal', 123.6)
            ->assertSet('per_100g_fat', 1.236)
            ->assertSet('per_100g_saturated_fat', 0.994)
            ->assertSet('per_serving_sugars', 10.555)
            ->assertSet('per_serving_salt', 0.004);

        $ingredient = Ingredient::query()
            ->where('name', 'Rounded Nutrition Ingredient')
            ->first();

        $this->assertNotNull($ingredient);
        $this->assertSame('123.600000000000000000', data_get($ingredient->nutriments, 'per_100g.energy_kcal'));
        $this->assertSame('1.236000000000000000', data_get($ingredient->nutriments, 'per_100g.fat'));
        $this->assertSame('0.994000000000000000', data_get($ingredient->nutriments, 'per_100g.saturated_fat'));
        $this->assertSame('10.555000000000000000', data_get($ingredient->nutriments, 'per_serving.sugars'));
        $this->assertSame('0.004000000000000000', data_get($ingredient->nutriments, 'per_serving.salt'));
    }

    public function test_fetch_from_off_uses_the_component_barcode(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Http::fake([
            'https://world.openfoodfacts.org/api/v3.4/product/*' => Http::response([
                'status' => 'success',
                'result' => ['id' => 'product_found'],
                'product' => [
                    'code' => '1234567890123',
                    'product_name' => 'OFF Test Product',
                    'keywords' => ['test'],
                    'categories_tags' => ['en:test-category'],
                    'nutriments' => [
                        'energy-kcal_100g' => 123.6,
                        'fat_100g' => 1.236,
                    ],
                ],
            ]),
        ]);

        Livewire::test(Form::class)
            ->set('barcode', '1234567890123')
            ->call('fetchFromOff')
            ->assertHasNoErrors('barcode')
            ->assertSet('barcode', '1234567890123')
            ->assertSet('name', 'OFF Test Product')
            ->assertSet('per_100g_energy_kcal', 123.6)
            ->assertSet('per_100g_fat', 1.236);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/1234567890123.json');
        });
    }

    public function test_fetch_from_off_redirects_to_existing_barcode_ingredient(): void
    {
        $user = User::factory()->create();

        $ingredient = Ingredient::factory()->for($user)->create([
            'name' => 'Existing Barcode Ingredient',
            'barcode' => '1234567890123',
            'quantity' => 1,
            'quantity_unit' => 'g',
        ]);

        $this->actingAs($user);

        Http::fake();

        Livewire::test(Form::class)
            ->set('barcode', '1234567890123')
            ->call('fetchFromOff')
            ->assertRedirect(route('ingredients.show', $ingredient, false));

        Http::assertNothingSent();
    }

    public function test_owner_can_view_ingredient_show_page(): void
    {
        $user = User::factory()->create();

        $ingredient = Ingredient::factory()->for($user)->create([
            'name' => 'Visible Ingredient',
            'barcode' => '9876543210000',
            'quantity' => 4,
            'quantity_unit' => 'can',
            'serving_quantity' => 200,
            'serving_quantity_unit' => 'g',
            'recommended_servings' => 2,
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('ingredients.show', $ingredient));

        $response
            ->assertOk()
            ->assertSee('Visible Ingredient')
            ->assertSee('Barcode: 9876543210000')
            ->assertSee('Quantity', false)
            ->assertSee('(can)', false)
            ->assertSee('4 cans', false)
            ->assertSee('Recommended serving', false)
            ->assertSee('200g', false)
            ->assertSee('Recommended servings', false)
            ->assertSee('2', false);
    }

    public function test_save_redirects_to_existing_barcode_ingredient_instead_of_creating_duplicate(): void
    {
        $user = User::factory()->create();

        $ingredient = Ingredient::factory()->for($user)->create([
            'name' => 'Existing Barcode Ingredient',
            'barcode' => '1234567890123',
            'quantity' => 1,
            'quantity_unit' => 'g',
        ]);

        $this->actingAs($user);

        Livewire::test(Form::class)
            ->set('name', 'Duplicate Barcode Ingredient')
            ->set('barcode', '1234567890123')
            ->set('quantity', 2)
            ->set('quantity_unit', 'kg')
            ->call('save')
            ->assertRedirect(route('ingredients.show', $ingredient, false));

        $this->assertSame(1, Ingredient::count());
    }

    public function test_owner_sees_formatted_quantity_on_ingredients_index(): void
    {
        $user = User::factory()->create();

        Ingredient::factory()->for($user)->create([
            'name' => 'Indexed Ingredient',
            'barcode' => '1111111111111',
            'quantity' => 4,
            'quantity_unit' => 'can',
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('ingredients.index'));

        $response
            ->assertOk()
            ->assertSee('Indexed Ingredient')
            ->assertSee('4 cans', false)
            ->assertDontSee('4.000 can', false);
    }

    public function test_owner_can_view_ingredient_edit_page(): void
    {
        $user = User::factory()->create();

        $ingredient = Ingredient::factory()->for($user)->create([
            'name' => 'Editable Ingredient',
            'barcode' => '2222222222222',
            'quantity' => 1,
            'quantity_unit' => 'g',
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('ingredients.edit', $ingredient));

        $response
            ->assertOk()
            ->assertSee('Edit ingredient')
            ->assertSee('Editable Ingredient');
    }
}
