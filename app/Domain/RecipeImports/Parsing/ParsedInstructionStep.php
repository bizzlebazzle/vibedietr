<?php

namespace App\Domain\RecipeImports\Parsing;

final readonly class ParsedInstructionStep
{
    /** @param list<string> $warnings @param list<string> $uncertainFields */
    public function __construct(
        public string $text,
        public ?string $sectionKey = null,
        public array $warnings = [],
        public array $uncertainFields = [],
    ) {}

    public function requiresReview(): bool
    {
        return $this->warnings !== [] || $this->uncertainFields !== [];
    }
}
