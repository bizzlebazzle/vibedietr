<?php

namespace App\Domain\Shared;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class Decimal
{
    public const STORAGE_PRECISION = 38;

    public const STORAGE_SCALE = 18;

    public const CALCULATION_PRECISION = 50;

    public const DIVISION_GUARD_SCALE = 24;

    public const ROUNDING_MODE = RoundingMode::HALF_UP;

    public static function parse(string|int $value): BigDecimal
    {
        $value = trim((string) $value);

        if (! preg_match('/^\+?(?:0|[1-9]\d*)(?:\.\d+)?$/', $value)) {
            throw new InvalidArgumentException('A non-negative base-10 decimal value is required.');
        }

        try {
            return BigDecimal::of(ltrim($value, '+'));
        } catch (MathException $exception) {
            throw new InvalidArgumentException('A valid base-10 decimal value is required.', previous: $exception);
        }
    }

    public static function forStorage(BigDecimal $value): string
    {
        $quantized = $value->toScale(self::STORAGE_SCALE, self::ROUNDING_MODE);
        $integerDigits = strlen(ltrim(explode('.', (string) $quantized, 2)[0], '0'));

        if ($integerDigits > self::STORAGE_PRECISION - self::STORAGE_SCALE) {
            throw new InvalidArgumentException('The decimal value exceeds DECIMAL(38,18).');
        }

        return (string) $quantized;
    }
}
