<?php

namespace App\Domain\Catalogue;

use InvalidArgumentException;

final class CatalogueName
{
    public static function display(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        if ($value === '' || mb_strlen($value) > 255 || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
            throw new InvalidArgumentException('Food names must be safe non-blank text of at most 255 characters.');
        }

        return $value;
    }

    public static function normalize(string $value): string
    {
        return mb_strtolower(self::display($value));
    }

    public static function optional(?string $value, int $maximum = 255): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        if ($value === '' || mb_strlen($value) > $maximum || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
            throw new InvalidArgumentException("Text must be safe and at most {$maximum} characters.");
        }

        return $value;
    }
}
