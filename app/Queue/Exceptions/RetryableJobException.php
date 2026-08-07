<?php

namespace App\Queue\Exceptions;

use Throwable;

final class RetryableJobException extends JobOperationException
{
    public function __construct(string $safeErrorCode)
    {
        parent::__construct(
            $safeErrorCode,
            'transient',
            'The queued operation encountered a transient failure.',
        );
    }

    public static function fromUnexpected(Throwable $exception, string $safeErrorCode): self
    {
        unset($exception);

        return new self($safeErrorCode);
    }
}
