<?php

namespace App\Domain\RecipeImports\Ocr;

interface OcrExtractor
{
    public function extract(string $canonicalBytes, string $correlationId): OcrResult;
}
