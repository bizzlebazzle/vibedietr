<?php

namespace App\Domain\Nutrition;

enum NutrientBasis: string
{
    case Per100Gram = 'per_100g';
    case Per100Millilitre = 'per_100ml';
    case PerServing = 'per_serving';
    case PerRecipe = 'per_recipe';
    case PerIngredientQuantity = 'per_ingredient_quantity';
    case PerItem = 'per_item';
}
