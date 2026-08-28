<?php

namespace App\Domain\RecipeImports\Ocr;

final readonly class OcrResult
{
    /** @param list<OcrTextLine> $lines @param list<string> $warnings */
    public function __construct(
        public string $text,
        public array $lines,
        public array $warnings,
        public string $provider,
        public string $providerVersion,
        public string $language,
    ) {}

    public function usable(): bool
    {
        return trim($this->text) !== '';
    }

    public function materiallyUncertain(): bool
    {
        $important = array_filter($this->lines, fn (OcrTextLine $line): bool => $line->confidence !== 'reliable');

        return count($important) >= 2
            || ($this->lines !== [] && count($important) / count($this->lines) >= 0.2);
    }
}
