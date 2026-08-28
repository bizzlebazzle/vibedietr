<?php

namespace App\Integrations\RecipeWebpages;

use App\Queue\Exceptions\JobOperationException;

final class WebpageFetchException extends JobOperationException
{
    public function __construct(
        string $safeErrorCode,
        string $failureCategory,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($safeErrorCode, $failureCategory, 'The webpage import request failed safely.');
    }
}
