<?php

namespace App\Domain\Recipes;

use App\Domain\Shared\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class RecipeIngredientMatchThresholdPolicy
{
    public const VERSION = 1;

    public const MINIMUM_SELECTABLE_SCORE = '0.9500';

    public const HIGH_CONFIDENCE_SCORE = '0.9900';

    public function evaluate(string|int $candidateScore): ?AutomaticRecipeIngredientMatchEvidence
    {
        $score = Decimal::parse($candidateScore);

        if ($score->isGreaterThan(BigDecimal::one())) {
            throw new InvalidArgumentException('A match score must be between zero and one.');
        }

        if ($score->getScale() > Decimal::STORAGE_SCALE) {
            throw new InvalidArgumentException('A match score cannot exceed 18 decimal places.');
        }

        if ($score->isLessThan(self::MINIMUM_SELECTABLE_SCORE)) {
            return null;
        }

        $high = $score->isGreaterThanOrEqualTo(self::HIGH_CONFIDENCE_SCORE);

        return new AutomaticRecipeIngredientMatchEvidence(
            candidateScore: (string) $score->toScale(Decimal::STORAGE_SCALE, RoundingMode::UNNECESSARY),
            confidenceBand: $high
                ? RecipeIngredientMatchConfidenceBand::High
                : RecipeIngredientMatchConfidenceBand::Reviewable,
            thresholdVersion: self::VERSION,
            reviewState: $high
                ? RecipeIngredientMatchReviewState::Confirmed
                : RecipeIngredientMatchReviewState::NeedsReview,
        );
    }
}
