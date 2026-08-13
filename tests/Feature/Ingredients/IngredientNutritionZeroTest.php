<?php

namespace Tests\Feature\Ingredients;

use App\Domain\Ingredients\IngredientWriteNormalizer;
use App\Domain\Nutrition\Nutrient;
use App\Models\Ingredient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IngredientNutritionZeroTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('explicitZeroValues')]
    public function test_every_supported_nutrient_preserves_explicit_zero_in_both_buckets(
        int|float|string $input,
    ): void {
        $nutrients = array_fill_keys($this->nutrientKeys(), $input);

        $normalized = app(IngredientWriteNormalizer::class)->normalize([
            'quantity_unit' => 'g',
            'nutriments' => [
                'per_100g' => $nutrients,
                'per_serving' => $nutrients,
            ],
        ]);

        foreach (['per_100g', 'per_serving'] as $bucket) {
            foreach ($this->nutrientKeys() as $nutrient) {
                $this->assertSame(0, data_get($normalized, "nutriments.{$bucket}.{$nutrient}"));
            }
        }
    }

    #[DataProvider('missingValues')]
    public function test_every_supported_blank_nutrient_is_missing_in_both_buckets(mixed $input): void
    {
        $nutrients = array_fill_keys($this->nutrientKeys(), $input);

        $normalized = app(IngredientWriteNormalizer::class)->normalize([
            'quantity_unit' => 'g',
            'nutriments' => [
                'per_100g' => $nutrients,
                'per_serving' => $nutrients,
            ],
        ]);

        $this->assertNull($normalized['nutriments']);
    }

    public function test_non_zero_values_keep_storage_precision_in_both_buckets(): void
    {
        $nutrients = array_fill_keys($this->nutrientKeys(), '1.1234567890123456785');
        $nutrients['salt'] = '0.0049';

        $normalized = app(IngredientWriteNormalizer::class)->normalize([
            'quantity_unit' => 'g',
            'nutriments' => [
                'per_100g' => $nutrients,
                'per_serving' => $nutrients,
            ],
        ]);

        foreach (['per_100g', 'per_serving'] as $bucket) {
            foreach ($this->nutrientKeys() as $nutrient) {
                $expected = match ($nutrient) {
                    'energy_kj' => '4.700543205227654321',
                    'salt' => '0.004900000000000000',
                    default => '1.123456789012345679',
                };

                $this->assertSame($expected, data_get($normalized, "nutriments.{$bucket}.{$nutrient}"));
            }
        }
    }

    public function test_database_json_and_round_trip_distinguish_zero_null_and_missing_key(): void
    {
        $ingredient = Ingredient::factory()->for(User::factory())->create([
            'nutriments' => [
                'per_100g' => [
                    'fat' => 0,
                    'salt' => null,
                ],
            ],
        ]);

        $storedJson = DB::table('ingredients')->where('id', $ingredient->id)->value('nutriments');
        $this->assertIsString($storedJson);

        $stored = json_decode($storedJson, true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsInt($stored['per_100g']['fat']);
        $this->assertSame(0, $stored['per_100g']['fat']);
        $this->assertArrayHasKey('salt', $stored['per_100g']);
        $this->assertNull($stored['per_100g']['salt']);
        $this->assertArrayNotHasKey('sodium', $stored['per_100g']);

        $ingredient->refresh();

        $this->assertSame(0, data_get($ingredient->nutriments, 'per_100g.fat'));
        $this->assertNull(data_get($ingredient->nutriments, 'per_100g.salt'));
        $this->assertFalse(data_get($ingredient->nutriments, 'per_100g.sodium', false));
    }

    public function test_shared_write_omits_missing_keys_without_filtering_zero(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('ingredients.store'), [
            'name' => 'Zero nutrition',
            'quantity' => '1',
            'quantity_unit' => 'g',
            'nutriments' => [
                'per_100g' => ['fat' => 0, 'salt' => null],
                'per_serving' => ['fat' => '0', 'salt' => ''],
            ],
        ])->assertRedirect(route('ingredients.index'));

        $ingredient = Ingredient::query()->sole();
        $storedJson = DB::table('ingredients')->where('id', $ingredient->id)->value('nutriments');
        $this->assertIsString($storedJson);

        $stored = json_decode($storedJson, true, flags: JSON_THROW_ON_ERROR);

        foreach (['per_100g', 'per_serving'] as $bucket) {
            $this->assertIsInt($stored[$bucket]['fat']);
            $this->assertSame(['fat' => 0], $stored[$bucket]);
        }
    }

    public function test_zero_energy_is_displayed_instead_of_treated_as_not_set(): void
    {
        $user = User::factory()->create();
        $ingredient = Ingredient::factory()->for($user)->create([
            'nutriments' => ['per_100g' => ['energy_kcal' => 0]],
        ]);

        $this->actingAs($user)
            ->get(route('ingredients.show', $ingredient))
            ->assertOk()
            ->assertSee('0 kcal');
    }

    public static function explicitZeroValues(): iterable
    {
        yield 'integer zero' => [0];
        yield 'string zero' => ['0'];
        yield 'float zero' => [0.0];
        yield 'decimal string zero' => ['0.0'];
    }

    public static function missingValues(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'whitespace-only string' => ['   '];
    }

    /** @return list<string> */
    private function nutrientKeys(): array
    {
        return array_map(
            static fn (Nutrient $nutrient): string => $nutrient->value,
            Nutrient::cases(),
        );
    }
}
