<?php

namespace App\Domain\RecipeImports\Ocr;

use App\Queue\Exceptions\NonRetryableJobException;

final class DisabledManagedOcrExtractor implements ManagedOcrExtractor
{
    public function enabled(): bool
    {
        return false;
    }

    public function extract(string $canonicalBytes, string $correlationId): OcrResult
    {
        throw new NonRetryableJobException('ocr_fallback_disabled');
    }
}
