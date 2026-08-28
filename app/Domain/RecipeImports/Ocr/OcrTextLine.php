<?php

namespace App\Domain\RecipeImports\Ocr;

final readonly class OcrTextLine
{
    public function __construct(
        public string $text,
        public string $confidence,
        public bool $containsCriticalUncertainty,
        public ?float $nativeMinimum = null,
    ) {}
}
