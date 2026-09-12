<?php

namespace App\Domain\Nutrition;

enum RecipeNutritionSource: string
{
    case CreatorOverride = 'creator_override';
    case ImportedSource = 'imported_source';
    case IngredientEstimate = 'ingredient_estimate';

    public function label(): string
    {
        return match ($this) {
            self::CreatorOverride => 'Creator override',
            self::ImportedSource => 'Imported recipe source',
            self::IngredientEstimate => 'Ingredient estimate',
        };
    }
}
