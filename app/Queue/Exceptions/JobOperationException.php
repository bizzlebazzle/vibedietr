<?php

namespace App\Queue\Exceptions;

use InvalidArgumentException;
use RuntimeException;

abstract class JobOperationException extends RuntimeException
{
    public function __construct(
        public readonly string $safeErrorCode,
        public readonly string $failureCategory,
        string $safeMessage,
    ) {
        if (! preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $safeErrorCode)) {
            throw new InvalidArgumentException('A queue error code must be a bounded stable identifier.');
        }

        parent::__construct($safeMessage);
    }
}
