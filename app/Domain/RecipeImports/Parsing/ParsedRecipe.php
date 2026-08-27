<?php

namespace App\Domain\RecipeImports\Parsing;

final readonly class ParsedRecipe
{
    /**
     * @param  list<ParsedIngredientLine>  $ingredients
     * @param  list<ParsedInstructionSection>  $sections
     * @param  list<ParsedInstructionStep>  $steps
     * @param  list<string>  $warnings
     */
    public function __construct(
        public ?string $title,
        public ?string $servings,
        public array $ingredients,
        public array $sections,
        public array $steps,
        public array $warnings,
        public string $parserIdentifier,
        public string $parserVersion,
        public string $completionClassification,
    ) {}
}
