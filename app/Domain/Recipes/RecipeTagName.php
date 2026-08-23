<?php

namespace App\Domain\Recipes;

final class RecipeTagName
{
    public static function display(string $name): string
    {
        return trim($name);
    }

    public static function normalized(string $name): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($name)));
    }
}
