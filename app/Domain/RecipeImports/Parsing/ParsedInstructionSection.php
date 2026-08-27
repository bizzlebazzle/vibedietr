<?php

namespace App\Domain\RecipeImports\Parsing;

final readonly class ParsedInstructionSection
{
    public function __construct(public string $key, public string $name) {}
}
