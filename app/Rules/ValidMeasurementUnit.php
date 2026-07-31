<?php

namespace App\Rules;

use App\Domain\Measurements\MeasurementUnitRegistry;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

final class ValidMeasurementUnit implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a unit name.');

            return;
        }

        try {
            MeasurementUnitRegistry::normalize($value);
        } catch (InvalidArgumentException) {
            $fail('The :attribute must be a safe unit name of at most 32 characters.');
        }
    }
}
