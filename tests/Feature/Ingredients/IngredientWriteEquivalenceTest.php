<?php

namespace Tests\Feature\Ingredients;

use App\Domain\Ingredients\IngredientWriteContract;
use App\Livewire\Ingredients\Form;
use App\Models\Ingredient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IngredientWriteEquivalenceTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('payloadCases')]
    public function test_controller_and_livewire_writes_have_equivalent_domain_outcomes(
        array $payload,
        bool $accepted,
        ?string $controllerError = null,
        ?string $livewireError = null,
        array $expected = [],
    ): void {
        $controllerUser = User::factory()->create();
        $livewireUser = User::factory()->create();

        $response = $this->actingAs($controllerUser)->post(route('ingredients.store'), $payload);
        $component = $this->livewireSubmission($livewireUser, $payload);

        if (! $accepted) {
            $response->assertSessionHasErrors($controllerError);
            $component->assertHasErrors($livewireError ?? $controllerError);
            $this->assertDatabaseCount('ingredients', 0);

            return;
        }

        $response->assertRedirect(route('ingredients.index'));
        $component->assertHasNoErrors();

        $controllerIngredient = Ingredient::query()->whereBelongsTo($controllerUser)->sole();
        $livewireIngredient = Ingredient::query()->whereBelongsTo($livewireUser)->sole();

        $this->assertSame(
            $this->domainValues($controllerIngredient),
            $this->domainValues($livewireIngredient),
        );

        foreach ($expected as $key => $value) {
            $this->assertSame($value, data_get($this->domainValues($controllerIngredient), $key));
        }
    }

    public static function payloadCases(): iterable
    {
        yield 'minimal manual ingredient and omitted nullable fields' => [
            self::validPayload(), true, null, null,
            ['barcode' => null, 'nutriments' => null, 'serving_quantity' => null],
        ];
        yield 'standard unit' => [
            self::validPayload(['quantity_unit' => 'g']), true, null, null, ['quantity_unit' => 'g'],
        ];
        yield 'standard unit alias' => [
            self::validPayload(['quantity_unit' => 'grams']), true, null, null, ['quantity_unit' => 'g'],
        ];
        yield 'custom unit' => [
            self::validPayload(['quantity_unit' => 'pinch']), true, null, null, ['quantity_unit' => 'pinch'],
        ];
        yield 'ambiguous unit remains custom' => [
            self::validPayload(['quantity_unit' => 'T']), true, null, null, ['quantity_unit' => 'T'],
        ];
        yield 'unsafe unit is rejected' => [
            self::validPayload(['quantity_unit' => "bad\nunit"]), false, 'quantity_unit',
        ];
        yield 'cross-dimension units are not converted' => [
            self::validPayload([
                'quantity_unit' => 'grams',
                'serving_quantity' => '25',
                'serving_quantity_unit' => 'millilitres',
            ]), true, null, null, ['quantity_unit' => 'g', 'serving_quantity_unit' => 'ml'],
        ];
        yield 'serving pair both present' => [
            self::validPayload(['serving_quantity' => '25', 'serving_quantity_unit' => 'grams']),
            true, null, null, ['serving_quantity' => '25.000', 'serving_quantity_unit' => 'g'],
        ];
        yield 'serving quantity without unit' => [
            self::validPayload(['serving_quantity' => '25']), false, 'serving_quantity_unit',
        ];
        yield 'serving unit without quantity' => [
            self::validPayload(['serving_quantity_unit' => 'g']), false, 'serving_quantity',
        ];
        yield 'explicit nullable fields' => [
            self::validPayload([
                'barcode' => null,
                'keywords' => null,
                'categories' => null,
                'nutriments' => null,
                'recommended_servings' => null,
                'image_url' => null,
            ]), true,
        ];
        yield 'empty and whitespace-only optional strings' => [
            self::validPayload(['barcode' => '   ', 'image_url' => '']),
            true, null, null, ['barcode' => null, 'image_url' => null],
        ];
        yield 'leading-zero barcode is preserved and surrounding whitespace removed' => [
            self::validPayload(['barcode' => '  0012345678905  ']),
            true, null, null, ['barcode' => '0012345678905'],
        ];
        yield 'barcode must be a string' => [
            self::validPayload(['barcode' => 123456789]), false, 'barcode',
        ];
        yield 'oversized barcode' => [
            self::validPayload(['barcode' => str_repeat('1', 65)]), false, 'barcode',
        ];
        yield 'supported nutrition with legacy aliases' => [
            self::validPayload(['nutriments' => [
                'per_100g' => ['energy_kcal' => '123.6', 'fiber' => '1.25', 'proteins' => '4.5'],
                'per_serving' => ['salt' => '0.345'],
            ]]), true, null, null, [
                'nutriments.per_100g.energy_kcal' => '123.600000000000000000',
                'nutriments.per_100g.fibre' => '1.250000000000000000',
                'nutriments.per_100g.protein' => '4.500000000000000000',
                'nutriments.per_serving.salt' => '0.345000000000000000',
            ],
        ];
        yield 'unsupported nutrient key' => [
            self::validPayload(['nutriments' => ['per_100g' => ['mystery' => '1']]]),
            false, 'nutriments.per_100g',
        ];
        yield 'negative nutrient' => [
            self::validPayload(['nutriments' => ['per_100g' => ['fat' => '-0.1']]]),
            false, 'nutriments.per_100g.fat', 'per_100g_fat',
        ];
        yield 'too-large nutrient' => [
            self::validPayload(['nutriments' => ['per_100g' => ['fat' => '100000000000000000000']]]),
            false, 'nutriments.per_100g.fat', 'per_100g_fat',
        ];
        yield 'excess nutrient precision is quantized once at storage' => [
            self::validPayload(['nutriments' => ['per_100g' => ['fat' => '1.1234567890123456785']]]),
            true, null, null, ['nutriments.per_100g.fat' => '1.123456789012345679'],
        ];
        yield 'null optional nutrient remains missing' => [
            self::validPayload(['nutriments' => ['per_100g' => ['fat' => null]]]),
            true, null, null, ['nutriments' => null],
        ];
        yield 'numeric nutrient zero is preserved' => [
            self::validPayload(['nutriments' => ['per_100g' => ['fat' => 0]]]),
            true, null, null, ['nutriments.per_100g.fat' => 0],
        ];
        yield 'numeric float nutrient zero is preserved' => [
            self::validPayload(['nutriments' => ['per_100g' => ['fat' => 0.0]]]),
            true, null, null, ['nutriments.per_100g.fat' => 0],
        ];
        yield 'decimal string nutrient zero is preserved' => [
            self::validPayload(['nutriments' => ['per_100g' => ['fat' => '0.0']]]),
            true, null, null, ['nutriments.per_100g.fat' => 0],
        ];
        yield 'string nutrient zero is preserved' => [
            self::validPayload(['nutriments' => ['per_serving' => ['salt' => '0']]]),
            true, null, null, ['nutriments.per_serving.salt' => 0],
        ];
        yield 'whitespace-only nutrient remains missing' => [
            self::validPayload(['nutriments' => ['per_serving' => ['salt' => '   ']]]),
            true, null, null, ['nutriments' => null],
        ];
        yield 'empty per-100g nutrient remains missing' => [
            self::validPayload(['nutriments' => ['per_100g' => ['fat' => '']]]),
            true, null, null, ['nutriments' => null],
        ];
        yield 'null per-serving nutrient remains missing' => [
            self::validPayload(['nutriments' => ['per_serving' => ['salt' => null]]]),
            true, null, null, ['nutriments' => null],
        ];
        yield 'small per-serving nutrient remains non-zero' => [
            self::validPayload(['nutriments' => ['per_serving' => ['salt' => '0.0049']]]),
            true, null, null, ['nutriments.per_serving.salt' => '0.004900000000000000'],
        ];
        yield 'nutrition is not rounded to display precision' => [
            self::validPayload(['nutriments' => ['per_100g' => ['salt' => '0.0049']]]),
            true, null, null, ['nutriments.per_100g.salt' => '0.004900000000000000'],
        ];
        yield 'numeric zero nullable values are preserved' => [
            self::validPayload([
                'recommended_servings' => 0,
                'serving_quantity' => 0,
                'serving_quantity_unit' => 'g',
            ]), true, null, null, ['recommended_servings' => '0.00', 'serving_quantity' => '0.000'],
        ];
        yield 'string zero nullable values are preserved' => [
            self::validPayload([
                'recommended_servings' => '0',
                'serving_quantity' => '0',
                'serving_quantity_unit' => 'g',
            ]), true, null, null, ['recommended_servings' => '0.00', 'serving_quantity' => '0.000'],
        ];
    }

    private function livewireSubmission(User $user, array $payload)
    {
        $component = Livewire::actingAs($user)->test(Form::class);

        foreach (Arr::except($payload, ['nutriments']) as $field => $value) {
            $component->set($field, $value);
        }

        if (array_key_exists('nutriments', $payload)) {
            $component->set('nutriments', $payload['nutriments']);

            if (is_array($payload['nutriments'])) {
                foreach (IngredientWriteContract::nutritionInputMap() as $property => $location) {
                    $path = "{$location['bucket']}.{$location['key']}";

                    if (Arr::has($payload['nutriments'], $path)) {
                        $component->set($property, data_get($payload['nutriments'], $path));
                    }
                }
            }
        }

        return $component->call('save');
    }

    /** @return array<string, mixed> */
    private function domainValues(Ingredient $ingredient): array
    {
        return Arr::only($ingredient->toArray(), IngredientWriteContract::fields());
    }

    private static function validPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Equivalent ingredient',
            'quantity' => '1',
            'quantity_unit' => 'g',
        ], $overrides);
    }
}
