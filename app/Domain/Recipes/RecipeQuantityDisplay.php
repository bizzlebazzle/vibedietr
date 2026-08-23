<?php

namespace App\Domain\Recipes;

final readonly class RecipeQuantityDisplay
{
    /**
     * @param  list<array{original_text: string, structured: bool, quantity: string|null, unit: string|null, generic_wording: string|null, notes: string|null}>  $ingredients
     */
    public function __construct(
        public ?string $originalServings,
        public ?string $requestedServings,
        public array $ingredients,
        public bool $canResize,
        public ?string $error = null,
    ) {}
}
