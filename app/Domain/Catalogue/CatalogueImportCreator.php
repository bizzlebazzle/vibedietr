<?php

namespace App\Domain\Catalogue;

use App\Models\User;

interface CatalogueImportCreator
{
    public function createOrReuse(
        User $submitter,
        string $barcode,
        CatalogueImportData $mapped,
    ): CatalogueBarcodeImportResult;
}
