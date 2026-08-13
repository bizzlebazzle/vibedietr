<?php

namespace Tests\Feature\Ingredients;

use App\Domain\Nutrition\Nutrient;
use App\Domain\Nutrition\NutrientRegistry;
use App\Livewire\Ingredients\Form;
use App\Models\Ingredient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class IngredientNutrientHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_controller_kcal_only_write_preserves_precision_and_derives_kilojoules(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('ingredients.store'), $this->payload([
            'nutriments' => [
                'per_100g' => ['energy_kcal' => '1.234567890123456789'],
            ],
        ]))->assertRedirect(route('ingredients.index'));

        $nutriments = Ingredient::query()->sole()->nutriments;

        $this->assertSame('1.234567890123456789', data_get($nutriments, 'per_100g.energy_kcal'));
        $this->assertSame('5.165432052276543205', data_get($nutriments, 'per_100g.energy_kj'));
    }

    public function test_controller_kj_only_write_derives_canonical_kcal_without_a_conversion_loop(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('ingredients.store'), $this->payload([
            'nutriments' => [
                'per_serving' => ['energy_kj' => '1.234567890123456789'],
            ],
        ]))->assertRedirect(route('ingredients.index'));

        $nutriments = Ingredient::query()->sole()->nutriments;

        $this->assertSame('0.295068807390883554', data_get($nutriments, 'per_serving.energy_kcal'));
        $this->assertSame('1.234567890123456790', data_get($nutriments, 'per_serving.energy_kj'));
    }

    public function test_open_food_facts_conflict_keeps_raw_sources_and_uses_kcal_for_normalized_energy(): void
    {
        $user = User::factory()->create();
        $sourceKcal = '100.123456789012345678';
        $sourceKilojoules = '999.123456789012345678';

        Http::fake([
            'https://world.openfoodfacts.org/api/v3.4/product/*' => Http::response([
                'status' => 'success',
                'result' => ['id' => 'product_found'],
                'product' => [
                    'code' => '1234567890123',
                    'product_name' => 'Complete nutrient product',
                    'quantity' => '100 g',
                    'nutriments' => [
                        'energy-kcal_100g' => $sourceKcal,
                        'energy-kj_100g' => $sourceKilojoules,
                        'fat_100g' => '1.25',
                        'saturated-fat_100g' => '0.25',
                        'carbohydrates_100g' => '20.25',
                        'sugars_100g' => '3.25',
                        'fiber_100g' => '4.25',
                        'proteins_100g' => '0',
                        'salt_100g' => '0.345',
                        'sodium_100g' => '0.00125',
                    ],
                ],
            ]),
        ]);

        Livewire::actingAs($user)->test(Form::class)
            ->set('barcode', '1234567890123')
            ->call('fetchFromOff')
            ->assertSet('per_100g_protein', '0')
            ->call('save')
            ->assertHasNoErrors();

        $ingredient = Ingredient::query()->sole();
        $nutriments = $ingredient->nutriments;

        $this->assertSame($sourceKcal, data_get($nutriments, 'raw.energy-kcal_100g'));
        $this->assertSame($sourceKilojoules, data_get($nutriments, 'raw.energy-kj_100g'));
        $this->assertSame('100.123456789012345678', data_get($nutriments, 'per_100g.energy_kcal'));
        $this->assertSame('418.916543205227654317', data_get($nutriments, 'per_100g.energy_kj'));
        $this->assertSame(0, data_get($nutriments, 'per_100g.protein'));

        $this->assertEqualsCanonicalizing(
            ['energy_kcal', 'energy_kj', 'fat', 'saturated_fat', 'carbohydrates', 'sugars', 'fibre', 'protein', 'salt', 'sodium'],
            array_keys($nutriments['per_100g']),
        );
    }

    public function test_open_food_facts_numeric_literals_keep_precision_before_storage_rounding(): void
    {
        $user = User::factory()->create();

        Http::fake([
            'https://world.openfoodfacts.org/api/v3.4/product/*' => Http::response(
                '{"status":"success","result":{"id":"product_found"},"product":{"code":"1234567890123","product_name":"Precise source","quantity":"100 g","nutriments":{"energy-kcal_100g":100.1234567890123456789,"energy-kj_100g":999.1234567890123456789,"proteins_100g":1.2345678901234567894}}}',
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        Livewire::actingAs($user)->test(Form::class)
            ->set('barcode', '1234567890123')
            ->call('fetchFromOff')
            ->call('save')
            ->assertHasNoErrors();

        $nutriments = Ingredient::query()->sole()->nutriments;

        $this->assertSame('100.1234567890123456789', data_get($nutriments, 'raw.energy-kcal_100g'));
        $this->assertSame('999.1234567890123456789', data_get($nutriments, 'raw.energy-kj_100g'));
        $this->assertSame('100.123456789012345679', data_get($nutriments, 'per_100g.energy_kcal'));
        $this->assertSame('418.916543205227654321', data_get($nutriments, 'per_100g.energy_kj'));
        $this->assertSame('1.234567890123456789', data_get($nutriments, 'per_100g.protein'));
    }

    public function test_full_supported_nutrient_set_uses_registry_display_rules_on_the_detail_path(): void
    {
        $user = User::factory()->create();
        $ingredient = Ingredient::factory()->for($user)->create([
            'nutriments' => [
                'per_100g' => [
                    'energy_kcal' => '12.500000000000000000',
                    'energy_kj' => '999.000000000000000000',
                    'fat' => '1.250000000000000000',
                    'saturated_fat' => '2.250000000000000000',
                    'carbohydrates' => '3.250000000000000000',
                    'sugars' => '4.250000000000000000',
                    'fibre' => '5.250000000000000000',
                    'protein' => '0.040000000000000000',
                    'salt' => '0.345000000000000000',
                    'sodium' => '0.001250000000000000',
                ],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('ingredients.show', $ingredient));

        $response->assertOk()
            ->assertSee('13 kcal / 52 kJ')
            ->assertSee('1.3 g')
            ->assertSee('2.3 g')
            ->assertSee('3.3 g')
            ->assertSee('4.3 g')
            ->assertSee('5.3 g')
            ->assertSee('<0.1 g')
            ->assertSee('0.35 g')
            ->assertSee('1 mg');

        foreach (NutrientRegistry::all() as $definition) {
            $response->assertSee($definition->label);
        }

        $ingredient->refresh();
        $this->assertSame('12.500000000000000000', data_get($ingredient->nutriments, 'per_100g.energy_kcal'));
        $this->assertSame('0.040000000000000000', data_get($ingredient->nutriments, 'per_100g.protein'));
    }

    public function test_protein_zero_and_missing_use_decided_display_semantics(): void
    {
        $user = User::factory()->create();
        $zero = Ingredient::factory()->for($user)->create([
            'name' => 'Zero protein',
            'nutriments' => ['per_100g' => ['protein' => 0]],
        ]);
        $missing = Ingredient::factory()->for($user)->create([
            'name' => 'Missing protein',
            'nutriments' => null,
        ]);

        $this->actingAs($user)->get(route('ingredients.show', $zero))
            ->assertOk()
            ->assertSee('0.0 g');

        $this->actingAs($user)->get(route('ingredients.show', $missing))
            ->assertOk()
            ->assertSee('Protein')
            ->assertSee('Not available');
    }

    public function test_persisted_json_keeps_decimal_strings_and_explicit_zero_types(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(Form::class)
            ->set('name', 'Decimal JSON')
            ->set('quantity', '1')
            ->set('quantity_unit', 'g')
            ->set('per_100g_energy_kcal', '0.000000000000000001')
            ->set('per_100g_protein', '0')
            ->call('save')
            ->assertHasNoErrors();

        $stored = json_decode(
            DB::table('ingredients')->where('name', 'Decimal JSON')->value('nutriments'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('0.000000000000000001', data_get($stored, 'per_100g.energy_kcal'));
        $this->assertSame('0.000000000000000004', data_get($stored, 'per_100g.energy_kj'));
        $this->assertSame(0, data_get($stored, 'per_100g.protein'));
    }

    public function test_form_exposes_every_supported_nutrient_without_a_duplicate_component_list(): void
    {
        $component = Livewire::actingAs(User::factory()->create())->test(Form::class);

        foreach (Nutrient::cases() as $nutrient) {
            $component->assertSet("per_100g_{$nutrient->value}", null);
            $component->assertSet("per_serving_{$nutrient->value}", null);
        }
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => 'Controller nutrient product',
            'barcode' => null,
            'keywords' => null,
            'categories' => null,
            'nutriments' => null,
            'quantity' => '100',
            'quantity_unit' => 'g',
            'serving_quantity' => null,
            'serving_quantity_unit' => null,
            'recommended_servings' => null,
            'image_url' => null,
        ], $overrides);
    }
}
