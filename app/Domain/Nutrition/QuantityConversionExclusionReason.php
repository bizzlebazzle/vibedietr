<?php

namespace App\Domain\Nutrition;

enum QuantityConversionExclusionReason: string
{
    case CustomUnit = 'custom_unit_not_convertible';
    case UnsupportedCountUnit = 'unsupported_count_unit';
    case InvalidDimensionCombination = 'invalid_dimension_combination';
    case FoodConversionMissing = 'food_conversion_missing';
    case FoodContextNotApproved = 'food_context_not_approved';
    case FoodConversionProvenanceMissing = 'food_conversion_provenance_missing';
}
