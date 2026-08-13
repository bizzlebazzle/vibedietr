<?php

namespace App\Rules;

use App\Domain\Shared\Decimal;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

final class ValidNutrientValue implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            $fail('The :attribute must be a non-negative decimal value.');

            return;
        }

        try {
            Decimal::forStorage(Decimal::parse(is_string($value) ? trim($value) : (string) $value));
        } catch (InvalidArgumentException) {
            $fail('The :attribute must be a non-negative decimal value within the supported range.');
        }
    }
}
