<?php

namespace App\Domain\Catalogue;

use InvalidArgumentException;

final class Barcode
{
    public const MAX_LENGTH = 64;

    public static function normalize(string $barcode): string
    {
        $barcode = trim($barcode);

        if ($barcode === '' || mb_strlen($barcode) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('A barcode must be a non-empty string of at most 64 characters.');
        }

        return $barcode;
    }
}
