<?php

namespace App\Domain\Ingredients;

enum IngredientBarcodeProvenance: string
{
    case Manual = 'manual';
    case MachineImported = 'machine_imported';
    case LegacyUnknown = 'legacy_unknown';
}
