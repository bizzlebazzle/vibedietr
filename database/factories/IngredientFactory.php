<?php

namespace Database\Factories;

use App\Domain\Ingredients\IngredientBarcodeProvenance;
use App\Integrations\OpenFoodFacts\OpenFoodFactsClient;
use App\Models\Ingredient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ingredient>
 */
class IngredientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'barcode' => null,
            'barcode_provenance' => IngredientBarcodeProvenance::Manual,
            'barcode_source' => null,
            'barcode_imported_at' => null,
            'keywords' => null,
            'categories' => null,
            'nutriments' => null,
            'quantity' => 1,
            'quantity_unit' => 'g',
            'serving_quantity' => null,
            'serving_quantity_unit' => null,
            'recommended_servings' => null,
            'image_url' => null,
        ];
    }

    /**
     * Represent a user-entered ingredient without external product data.
     *
     * Do not combine this state with barcodeImported(); they represent
     * mutually exclusive source conventions in the current schema.
     */
    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'barcode' => null,
            'barcode_provenance' => IngredientBarcodeProvenance::Manual,
            'barcode_source' => null,
            'barcode_imported_at' => null,
            'keywords' => null,
            'categories' => null,
            'image_url' => null,
        ]);
    }

    /**
     * Represent data from a verified OpenFoodFacts barcode import.
     *
     * Do not combine this state with manual(); they represent mutually
     * exclusive provenance classifications.
     */
    public function barcodeImported(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Imported test product '.fake()->unique()->numerify('####'),
            'barcode' => fake()->unique()->numerify('0############'),
            'barcode_provenance' => IngredientBarcodeProvenance::MachineImported,
            'barcode_source' => OpenFoodFactsClient::PROVIDER,
            'barcode_imported_at' => now()->utc(),
            'keywords' => ['synthetic', 'imported'],
            'categories' => ['en:test-foods'],
            'quantity' => 400,
            'quantity_unit' => 'g',
            'serving_quantity' => 100,
            'serving_quantity_unit' => 'g',
            'image_url' => 'https://example.test/products/synthetic-product.jpg',
        ]);
    }

    /**
     * Represent a pre-STB-08 barcode whose import origin cannot be proven.
     */
    public function legacyBarcode(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Legacy barcode product '.fake()->unique()->numerify('####'),
            'barcode' => fake()->unique()->numerify('9############'),
            'barcode_provenance' => IngredientBarcodeProvenance::LegacyUnknown,
            'barcode_source' => null,
            'barcode_imported_at' => null,
        ]);
    }

    /**
     * Add representative nutrition using the current legacy JSON convention.
     */
    public function withNutrition(): static
    {
        return $this->state(fn (array $attributes) => [
            'nutriments' => [
                'per_100g' => [
                    'energy_kcal' => 245,
                    'energy_kj' => 1025,
                    'carbohydrates' => 30.4,
                    'fat' => 8.2,
                    'fiber' => 4.1,
                    'proteins' => 12.6,
                    'salt' => 0.72,
                    'saturated_fat' => 1.3,
                    'sodium' => 0.288,
                    'sugars' => 3.8,
                ],
                'per_serving' => [
                    'energy_kcal' => 123,
                    'energy_kj' => 513,
                    'carbohydrates' => 15.2,
                    'fat' => 4.1,
                    'fiber' => 2.05,
                    'proteins' => 6.3,
                    'salt' => 0.36,
                    'saturated_fat' => 0.65,
                    'sodium' => 0.144,
                    'sugars' => 1.9,
                ],
            ],
        ]);
    }

    /**
     * Represent a safe custom unit that has no conversion behavior.
     */
    public function unusualUnit(): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity' => 2,
            'quantity_unit' => 'sprig',
        ]);
    }
}
