<?php

namespace App\Domain\Catalogue;

final readonly class LegacyIngredientClassificationResult
{
    public function __construct(
        public LegacyIngredientClassification $classification,
        public ?LegacyIngredientReviewReason $reviewReason = null,
    ) {}

    public function canCreateCandidate(): bool
    {
        return in_array($this->classification, [
            LegacyIngredientClassification::LegacyManual,
            LegacyIngredientClassification::VerifiedImported,
        ], true);
    }
}
