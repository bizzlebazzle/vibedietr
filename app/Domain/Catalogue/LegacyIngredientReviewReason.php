<?php

namespace App\Domain\Catalogue;

enum LegacyIngredientReviewReason: string
{
    case MalformedBarcode = 'malformed_barcode';
    case UnverifiedLegacyBarcode = 'unverified_legacy_barcode';
    case MissingBarcode = 'missing_barcode';
    case MissingImportSource = 'missing_import_source';
    case MissingImportTimestamp = 'missing_import_timestamp';
    case ConflictingImportProvenance = 'conflicting_import_provenance';
    case DuplicateBarcode = 'duplicate_barcode';
}
