<?php

namespace App\Domain\RecipeImports\Parsing;

final readonly class ParsedIngredientLine
{
    /** @param list<string> $warnings @param list<string> $uncertainFields */
    public function __construct(
        public string $originalText,
        public ?string $quantity = null,
        public ?string $unit = null,
        public ?string $genericWording = null,
        public ?string $notes = null,
        public array $warnings = [],
        public array $uncertainFields = [],
    ) {}

    public function requiresReview(): bool
    {
        return $this->warnings !== [] || $this->uncertainFields !== [];
    }
}
