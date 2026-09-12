<?php

namespace App\Domain\MealPlans;

enum PlanSlotKey: string
{
    case Breakfast = 'breakfast';
    case Lunch = 'lunch';
    case Dinner = 'dinner';
    case Drinks = 'drinks';
    case Snacks = 'snacks';

    public function defaultName(): string
    {
        return ucfirst($this->value);
    }

    public function hasFixedName(): bool
    {
        return in_array($this, [self::Drinks, self::Snacks], true);
    }
}
