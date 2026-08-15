<?php

namespace App\Domain\Recipes;

enum RecipeLifecycle: string
{
    case Draft = 'draft';
    case Finalized = 'finalized';
}
