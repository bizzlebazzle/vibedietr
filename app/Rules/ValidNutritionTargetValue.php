<?php

namespace App\Rules;

use App\Domain\Nutrition\NutrientDefinition;
use App\Domain\Nutrition\NutrientUnitConverter;
use App\Domain\Shared\Decimal;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

final readonly class ValidNutritionTargetValue implements ValidationRule
{
    public function __construct(
        private NutrientDefinition $definition,
        private NutrientUnitConverter $converter = new NutrientUnitConverter,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            $fail('The :attribute must be a non-negative decimal value.');

            return;
        }

        try {
            $displayValue = Decimal::parse(is_string($value) ? trim($value) : (string) $value);
            $canonicalValue = $this->converter->convert(
                $displayValue,
                $this->definition->preferredDisplayUnit,
                $this->definition->canonicalStorageUnit,
            );
            Decimal::forStorage($canonicalValue);
        } catch (InvalidArgumentException) {
            $fail('The :attribute must be a non-negative decimal value within the supported range.');
        }
    }
}
