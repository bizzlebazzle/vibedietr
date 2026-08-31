<?php

namespace App\Domain\Catalogue;

use App\Domain\Ingredients\IngredientBarcodeProvenance;
use App\Integrations\OpenFoodFacts\OpenFoodFactsClient;

final class LegacyIngredientClassifier
{
    public function classify(object $ingredient, bool $hasDuplicateTrustedIdentifier): LegacyIngredientClassificationResult
    {
        $barcode = (string) ($ingredient->barcode ?? '');
        $normalizedBarcode = trim($barcode);
        $provenance = (string) $ingredient->barcode_provenance;
        $source = $ingredient->barcode_source === null ? null : (string) $ingredient->barcode_source;
        $importedAt = $ingredient->barcode_imported_at;

        if ($normalizedBarcode === '') {
            if ($provenance === IngredientBarcodeProvenance::Manual->value
                && $source === null
                && $importedAt === null) {
                return new LegacyIngredientClassificationResult(
                    LegacyIngredientClassification::LegacyManual,
                );
            }

            return new LegacyIngredientClassificationResult(
                LegacyIngredientClassification::AmbiguousBarcode,
                LegacyIngredientReviewReason::MissingBarcode,
            );
        }

        if ($barcode !== $normalizedBarcode) {
            return new LegacyIngredientClassificationResult(
                LegacyIngredientClassification::AmbiguousBarcode,
                LegacyIngredientReviewReason::MalformedBarcode,
            );
        }

        if ($provenance === IngredientBarcodeProvenance::LegacyUnknown->value) {
            return new LegacyIngredientClassificationResult(
                LegacyIngredientClassification::AmbiguousBarcode,
                LegacyIngredientReviewReason::UnverifiedLegacyBarcode,
            );
        }

        if ($provenance !== IngredientBarcodeProvenance::MachineImported->value) {
            return new LegacyIngredientClassificationResult(
                LegacyIngredientClassification::AmbiguousBarcode,
                LegacyIngredientReviewReason::ConflictingImportProvenance,
            );
        }

        if ($source === null || $source === '') {
            return new LegacyIngredientClassificationResult(
                LegacyIngredientClassification::AmbiguousBarcode,
                LegacyIngredientReviewReason::MissingImportSource,
            );
        }

        if ($source !== OpenFoodFactsClient::PROVIDER) {
            return new LegacyIngredientClassificationResult(
                LegacyIngredientClassification::AmbiguousBarcode,
                LegacyIngredientReviewReason::ConflictingImportProvenance,
            );
        }

        if ($importedAt === null) {
            return new LegacyIngredientClassificationResult(
                LegacyIngredientClassification::AmbiguousBarcode,
                LegacyIngredientReviewReason::MissingImportTimestamp,
            );
        }

        if ($hasDuplicateTrustedIdentifier) {
            return new LegacyIngredientClassificationResult(
                LegacyIngredientClassification::Duplicate,
                LegacyIngredientReviewReason::DuplicateBarcode,
            );
        }

        return new LegacyIngredientClassificationResult(
            LegacyIngredientClassification::VerifiedImported,
        );
    }
}
