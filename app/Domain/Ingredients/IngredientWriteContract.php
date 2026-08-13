<?php

namespace App\Domain\Ingredients;

use App\Domain\Nutrition\Nutrient;
use App\Domain\Nutrition\NutrientRegistry;
use App\Rules\ValidMeasurementUnit;
use App\Rules\ValidNutrientValue;

final class IngredientWriteContract
{
    /** @return list<string> */
    public static function fields(): array
    {
        return [
            'name',
            'barcode',
            'keywords',
            'categories',
            'nutriments',
            'quantity',
            'quantity_unit',
            'serving_quantity',
            'serving_quantity_unit',
            'recommended_servings',
            'image_url',
        ];
    }

    /**
     * Apply transport-neutral whitespace handling before validation.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function prepare(array $input): array
    {
        foreach (['name', 'barcode', 'quantity_unit', 'serving_quantity_unit', 'image_url'] as $field) {
            if (array_key_exists($field, $input) && is_string($input[$field])) {
                $input[$field] = trim($input[$field]);
            }
        }

        foreach (['barcode', 'serving_quantity_unit', 'image_url'] as $field) {
            if (($input[$field] ?? null) === '') {
                $input[$field] = null;
            }
        }

        if (isset($input['nutriments']) && is_array($input['nutriments'])) {
            foreach (['per_100g', 'per_serving'] as $bucket) {
                if (! isset($input['nutriments'][$bucket]) || ! is_array($input['nutriments'][$bucket])) {
                    continue;
                }

                foreach ($input['nutriments'][$bucket] as $key => $value) {
                    $input['nutriments'][$bucket][$key] = self::prepareNutrientValue($value);
                }
            }
        }

        foreach (array_keys(self::nutritionInputMap()) as $property) {
            if (array_key_exists($property, $input)) {
                $input[$property] = self::prepareNutrientValue($input[$property]);
            }
        }

        return $input;
    }

    private static function prepareNutrientValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        $nutrientKeys = implode(',', array_keys(self::nutrientKeyMap()));
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'keywords' => ['nullable', 'array'],
            'categories' => ['nullable', 'array'],
            'nutriments' => ['nullable', 'array:raw,per_100g,per_serving'],
            'nutriments.raw' => ['nullable', 'array'],
            'nutriments.per_100g' => ['nullable', "array:{$nutrientKeys}"],
            'nutriments.per_serving' => ['nullable', "array:{$nutrientKeys}"],
            'quantity' => ['required', 'numeric', 'min:0'],
            'quantity_unit' => ['required', 'string', 'max:32', new ValidMeasurementUnit],
            'serving_quantity' => ['nullable', 'required_with:serving_quantity_unit', 'numeric', 'min:0'],
            'serving_quantity_unit' => ['nullable', 'required_with:serving_quantity', 'string', 'max:32', new ValidMeasurementUnit],
            'recommended_servings' => ['nullable', 'numeric', 'min:0'],
            'image_url' => ['nullable', 'url'],
        ];

        foreach (['per_100g', 'per_serving'] as $bucket) {
            foreach (array_keys(self::nutrientKeyMap()) as $key) {
                $rules["nutriments.{$bucket}.{$key}"] = ['nullable', new ValidNutrientValue];
            }
        }

        return $rules;
    }

    /** @return array<string, array<int, mixed>> */
    public static function livewireRules(): array
    {
        $rules = self::rules();

        foreach (array_keys(self::nutritionInputMap()) as $property) {
            $rules[$property] = ['nullable', new ValidNutrientValue];
        }

        return $rules;
    }

    /** @return array<string, array{bucket: string, key: string}> */
    public static function nutritionInputMap(): array
    {
        $inputs = [];

        foreach (['per_100g', 'per_serving'] as $bucket) {
            foreach (NutrientRegistry::all() as $definition) {
                $key = $definition->id->value;
                $inputs["{$bucket}_{$key}"] = ['bucket' => $bucket, 'key' => $key];
            }
        }

        return $inputs;
    }

    /** @return array<string, Nutrient> */
    public static function nutrientKeyMap(): array
    {
        return [
            Nutrient::EnergyKcal->value => Nutrient::EnergyKcal,
            Nutrient::EnergyKj->value => Nutrient::EnergyKj,
            Nutrient::Fat->value => Nutrient::Fat,
            Nutrient::SaturatedFat->value => Nutrient::SaturatedFat,
            Nutrient::Carbohydrates->value => Nutrient::Carbohydrates,
            Nutrient::Sugars->value => Nutrient::Sugars,
            Nutrient::Fibre->value => Nutrient::Fibre,
            'fiber' => Nutrient::Fibre,
            Nutrient::Protein->value => Nutrient::Protein,
            'proteins' => Nutrient::Protein,
            Nutrient::Salt->value => Nutrient::Salt,
            Nutrient::Sodium->value => Nutrient::Sodium,
        ];
    }
}
