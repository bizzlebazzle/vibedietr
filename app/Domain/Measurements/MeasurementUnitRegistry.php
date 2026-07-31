<?php

namespace App\Domain\Measurements;

use InvalidArgumentException;

final class MeasurementUnitRegistry
{
    /** @return list<StandardUnitDefinition> */
    public static function all(): array
    {
        return [
            new StandardUnitDefinition(StandardUnit::Milligram, 'mg', 'Milligram', MeasurementDimension::Mass, '0.001', ['mg', 'milligram', 'milligrams']),
            new StandardUnitDefinition(StandardUnit::Gram, 'g', 'Gram', MeasurementDimension::Mass, '1', ['g', 'gram', 'grams']),
            new StandardUnitDefinition(StandardUnit::Kilogram, 'kg', 'Kilogram', MeasurementDimension::Mass, '1000', ['kg', 'kilogram', 'kilograms']),
            new StandardUnitDefinition(StandardUnit::Ounce, 'oz', 'Ounce', MeasurementDimension::Mass, '28.349523125', ['oz', 'ounce', 'ounces']),
            new StandardUnitDefinition(StandardUnit::Pound, 'lb', 'Pound', MeasurementDimension::Mass, '453.59237', ['lb', 'lbs', 'pound', 'pounds']),
            new StandardUnitDefinition(StandardUnit::Millilitre, 'ml', 'Millilitre', MeasurementDimension::Volume, '1', ['ml', 'millilitre', 'millilitres', 'milliliter', 'milliliters']),
            new StandardUnitDefinition(StandardUnit::Centilitre, 'cl', 'Centilitre', MeasurementDimension::Volume, '10', ['cl', 'centilitre', 'centilitres', 'centiliter', 'centiliters']),
            new StandardUnitDefinition(StandardUnit::Litre, 'l', 'Litre', MeasurementDimension::Volume, '1000', ['l', 'litre', 'litres', 'liter', 'liters']),
            new StandardUnitDefinition(StandardUnit::Teaspoon, 'tsp', 'UK teaspoon', MeasurementDimension::Volume, '5', ['tsp', 'tsps', 'teaspoon', 'teaspoons']),
            new StandardUnitDefinition(StandardUnit::Tablespoon, 'tbsp', 'UK tablespoon', MeasurementDimension::Volume, '15', ['tbsp', 'tbsps', 'tablespoon', 'tablespoons']),
            new StandardUnitDefinition(StandardUnit::FluidOunce, 'fl oz', 'US fluid ounce', MeasurementDimension::Volume, '29.5735295625', ['fl oz', 'fluid ounce', 'fluid ounces']),
            new StandardUnitDefinition(StandardUnit::Cup, 'cup', 'US cup', MeasurementDimension::Volume, '236.5882365', ['cup', 'cups']),
            new StandardUnitDefinition(StandardUnit::Pint, 'pt', 'US liquid pint', MeasurementDimension::Volume, '473.176473', ['pt', 'pts', 'pint', 'pints']),
            new StandardUnitDefinition(StandardUnit::Quart, 'qt', 'US liquid quart', MeasurementDimension::Volume, '946.352946', ['qt', 'qts', 'quart', 'quarts']),
            new StandardUnitDefinition(StandardUnit::Gallon, 'gal', 'US liquid gallon', MeasurementDimension::Volume, '3785.411784', ['gal', 'gals', 'gallon', 'gallons']),
            new StandardUnitDefinition(StandardUnit::Item, 'each', 'Item', MeasurementDimension::Count, null, ['each', 'item', 'items', 'unit', 'units']),
            new StandardUnitDefinition(StandardUnit::Piece, 'piece', 'Piece', MeasurementDimension::Count, null, ['piece', 'pieces', 'pc', 'pcs']),
            new StandardUnitDefinition(StandardUnit::Slice, 'slice', 'Slice', MeasurementDimension::Count, null, ['slice', 'slices']),
            new StandardUnitDefinition(StandardUnit::Clove, 'clove', 'Clove', MeasurementDimension::Count, null, ['clove', 'cloves']),
            new StandardUnitDefinition(StandardUnit::Serving, 'serving', 'Serving', MeasurementDimension::Count, null, ['serving', 'servings', 'serve', 'serves']),
            new StandardUnitDefinition(StandardUnit::Portion, 'portion', 'Portion', MeasurementDimension::Count, null, ['portion', 'portions']),
            new StandardUnitDefinition(StandardUnit::Can, 'can', 'Can', MeasurementDimension::Count, null, ['can', 'cans']),
            new StandardUnitDefinition(StandardUnit::Jar, 'jar', 'Jar', MeasurementDimension::Count, null, ['jar', 'jars']),
            new StandardUnitDefinition(StandardUnit::Bottle, 'bottle', 'Bottle', MeasurementDimension::Count, null, ['bottle', 'bottles']),
            new StandardUnitDefinition(StandardUnit::Carton, 'carton', 'Carton', MeasurementDimension::Count, null, ['carton', 'cartons']),
            new StandardUnitDefinition(StandardUnit::Packet, 'packet', 'Packet', MeasurementDimension::Count, null, ['packet', 'packets', 'pack', 'packs']),
            new StandardUnitDefinition(StandardUnit::Pouch, 'pouch', 'Pouch', MeasurementDimension::Count, null, ['pouch', 'pouches']),
            new StandardUnitDefinition(StandardUnit::Pot, 'pot', 'Pot', MeasurementDimension::Count, null, ['pot', 'pots']),
            new StandardUnitDefinition(StandardUnit::Tub, 'tub', 'Tub', MeasurementDimension::Count, null, ['tub', 'tubs']),
            new StandardUnitDefinition(StandardUnit::Stick, 'stick', 'Stick', MeasurementDimension::Count, null, ['stick', 'sticks']),
            new StandardUnitDefinition(StandardUnit::Bar, 'bar', 'Bar', MeasurementDimension::Count, null, ['bar', 'bars']),
        ];
    }

    public static function definition(StandardUnit $unit): StandardUnitDefinition
    {
        foreach (self::all() as $definition) {
            if ($definition->id === $unit) {
                return $definition;
            }
        }

        throw new InvalidArgumentException("Unknown standard unit: {$unit->value}");
    }

    public static function findByIdentifier(string $identifier): ?StandardUnit
    {
        return StandardUnit::tryFrom($identifier);
    }

    public static function findStandard(string $input): ?StandardUnit
    {
        $alias = self::normalizeAlias($input);

        foreach (self::all() as $definition) {
            foreach ($definition->aliases as $candidate) {
                if (self::normalizeAlias($candidate) === $alias) {
                    return $definition->id;
                }
            }
        }

        return null;
    }

    public static function normalize(string $input): StandardUnit|CustomUnit
    {
        return self::findStandard($input) ?? new CustomUnit($input);
    }

    /** @return array<string, array<string, string>> */
    public static function formGroups(): array
    {
        $groups = [
            'Weight' => [],
            'Volume' => [],
            'Pieces & portions' => [],
            'Packs & containers' => [],
        ];

        foreach (self::all() as $definition) {
            $group = match (true) {
                $definition->dimension === MeasurementDimension::Mass => 'Weight',
                $definition->dimension === MeasurementDimension::Volume => 'Volume',
                in_array($definition->id, [StandardUnit::Item, StandardUnit::Piece, StandardUnit::Slice, StandardUnit::Clove, StandardUnit::Serving, StandardUnit::Portion], true) => 'Pieces & portions',
                default => 'Packs & containers',
            };

            $groups[$group][$definition->symbol] = $definition->dimension === MeasurementDimension::Count
                ? $definition->label
                : "{$definition->label} ({$definition->symbol})";
        }

        return $groups;
    }

    /** @return list<string> */
    public static function suggestedCustomUnits(): array
    {
        return ['pinch', 'dash', 'handful', 'scoop', 'bunch', 'sprig', 'to taste'];
    }

    private static function normalizeAlias(string $input): string
    {
        $normalized = mb_strtolower(trim($input));
        $normalized = str_replace(['.', '_'], [' ', ' '], $normalized);

        return trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);
    }
}
