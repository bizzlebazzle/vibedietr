<?php

namespace App\Domain\Recipes;

use App\Domain\Shared\Decimal;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

final class RecipeQuantityScaler
{
    public function scale(
        string $originalQuantity,
        string $originalServings,
        string $requestedServings,
    ): BigDecimal {
        $quantity = Decimal::parse($originalQuantity);
        $savedServings = $this->positiveServings($originalServings, true);
        $requested = $this->positiveServings($requestedServings, false);

        return $quantity
            ->multipliedBy($requested)
            ->dividedBy($savedServings, Decimal::DIVISION_GUARD_SCALE, Decimal::ROUNDING_MODE);
    }

    private function positiveServings(string $servings, bool $saved): BigDecimal
    {
        try {
            $value = Decimal::parse($servings);
        } catch (InvalidArgumentException $exception) {
            if ($saved) {
                throw new InvalidOriginalRecipeServings;
            }

            throw $exception;
        }

        if (! $value->isPositive()) {
            if ($saved) {
                throw new InvalidOriginalRecipeServings;
            }

            throw new InvalidArgumentException('Requested servings must be greater than zero.');
        }

        return $value;
    }
}
