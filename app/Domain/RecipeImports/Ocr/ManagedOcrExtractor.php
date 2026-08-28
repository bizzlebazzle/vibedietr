<?php

namespace App\Domain\RecipeImports\Ocr;

interface ManagedOcrExtractor
{
    public function enabled(): bool;

    public function extract(string $canonicalBytes, string $correlationId): OcrResult;
}
