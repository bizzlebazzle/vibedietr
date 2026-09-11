<?php

namespace App\Domain\Nutrition;

use App\Domain\Measurements\StandardUnit;
use Brick\Math\BigDecimal;
use LogicException;

final readonly class QuantityConversionResult
{
    private function __construct(
        public ?BigDecimal $quantity,
        public ?StandardUnit $unit,
        public ?FoodQuantityConversionEvidence $foodConversion,
        public ?QuantityConversionExclusionReason $exclusionReason,
    ) {}

    public static function converted(
        BigDecimal $quantity,
        StandardUnit $unit,
        ?FoodQuantityConversionEvidence $foodConversion = null,
    ): self {
        return new self($quantity, $unit, $foodConversion, null);
    }

    public static function excluded(QuantityConversionExclusionReason $reason): self
    {
        return new self(null, null, null, $reason);
    }

    public function isExcluded(): bool
    {
        return $this->exclusionReason !== null;
    }

    public function convertedQuantity(): BigDecimal
    {
        return $this->quantity
            ?? throw new LogicException('An excluded quantity conversion has no converted quantity.');
    }
}
