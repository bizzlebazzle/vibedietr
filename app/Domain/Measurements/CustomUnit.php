<?php

namespace App\Domain\Measurements;

use InvalidArgumentException;

final readonly class CustomUnit
{
    public function __construct(public string $originalText)
    {
        if (trim($this->originalText) === '') {
            throw new InvalidArgumentException('A custom unit cannot be blank.');
        }

        if (mb_strlen($this->originalText) > 32) {
            throw new InvalidArgumentException('A custom unit cannot exceed 32 characters.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/u', $this->originalText)) {
            throw new InvalidArgumentException('A custom unit cannot contain control characters.');
        }
    }

    public function dimension(): MeasurementDimension
    {
        return MeasurementDimension::Custom;
    }
}
