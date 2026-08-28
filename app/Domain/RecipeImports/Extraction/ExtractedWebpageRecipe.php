<?php

namespace App\Domain\RecipeImports\Extraction;

use App\Domain\RecipeImports\Parsing\ParsedRecipe;

final readonly class ExtractedWebpageRecipe
{
    public function __construct(
        public ParsedRecipe $recipe,
        public string $sourceText,
        public string $method,
        public string $extractorIdentifier,
        public string $extractorVersion,
    ) {}
}
