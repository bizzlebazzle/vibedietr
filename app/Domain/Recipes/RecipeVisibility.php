<?php

namespace App\Domain\Recipes;

enum RecipeVisibility: string
{
    case Public = 'public';
    case Private = 'private';
}
