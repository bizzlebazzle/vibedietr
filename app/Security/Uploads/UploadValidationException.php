<?php

namespace App\Security\Uploads;

use RuntimeException;

final class UploadValidationException extends RuntimeException
{
    public static function oversized(): self
    {
        return new self('The uploaded input exceeds the configured size limit.');
    }

    public static function invalidContent(): self
    {
        return new self('The uploaded input type could not be accepted.');
    }

    public static function storageFailed(): self
    {
        return new self('The transient input could not be stored safely.');
    }
}
