<?php

namespace App\Domain\RecipeImports\Parsing;

interface RecipeTextParser
{
    public function parse(string $sourceText): ParsedRecipe;
}
