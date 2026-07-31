<?php

namespace App\Audit;

use InvalidArgumentException;

final class AuditReferenceValidator
{
    public const MAX_LENGTH = 64;

    public static function validate(?string $reference, string $field): ?string
    {
        if ($reference === null) {
            return null;
        }

        if (filter_var($reference, FILTER_VALIDATE_IP) !== false) {
            throw new InvalidArgumentException(
                "$field cannot be a raw IP address."
            );
        }

        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,63}\z/', $reference)) {
            throw new InvalidArgumentException(
                "$field must be a non-secret opaque reference of at most 64 characters."
            );
        }

        return $reference;
    }
}
