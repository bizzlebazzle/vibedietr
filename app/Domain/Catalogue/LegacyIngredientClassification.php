<?php

namespace App\Domain\Catalogue;

enum LegacyIngredientClassification: string
{
    case LegacyManual = 'legacy_manual';
    case VerifiedImported = 'verified_imported';
    case AmbiguousBarcode = 'ambiguous_barcode';
    case Duplicate = 'duplicate';
}
