<?php

namespace App\Domain\Recipes;

enum ManagedRecipeTermCategory: string
{
    case Dietary = 'dietary';
    case Cuisine = 'cuisine';
    case MealType = 'meal_type';

    public function label(): string
    {
        return match ($this) {
            self::Dietary => 'Dietary',
            self::Cuisine => 'Cuisine',
            self::MealType => 'Meal type',
        };
    }
}
