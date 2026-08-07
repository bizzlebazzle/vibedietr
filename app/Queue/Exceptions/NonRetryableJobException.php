<?php

namespace App\Queue\Exceptions;

final class NonRetryableJobException extends JobOperationException
{
    public function __construct(string $safeErrorCode)
    {
        parent::__construct(
            $safeErrorCode,
            'permanent',
            'The queued operation failed permanently.',
        );
    }
}
