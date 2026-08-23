<?php

namespace App\Domain\Recipes;

use App\Domain\Shared\Decimal;
use Brick\Math\BigDecimal;

final class RecipeQuantityFormatter
{
    public const DISPLAY_SCALE = 3;

    public function format(BigDecimal|string $quantity): string
    {
        $value = is_string($quantity) ? Decimal::parse($quantity) : $quantity;
        $rounded = $value->toScale(self::DISPLAY_SCALE, Decimal::ROUNDING_MODE);

        if ($value->isPositive() && $rounded->isZero()) {
            return '<0.001';
        }

        return $this->trimTrailingZeros((string) $rounded);
    }

    private function trimTrailingZeros(string $value): string
    {
        if (! str_contains($value, '.')) {
            return $value;
        }

        return rtrim(rtrim($value, '0'), '.');
    }
}
