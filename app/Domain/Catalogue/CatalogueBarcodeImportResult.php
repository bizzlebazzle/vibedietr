<?php

namespace App\Domain\Catalogue;

use App\Models\CatalogueItem;

final readonly class CatalogueBarcodeImportResult
{
    public function __construct(
        public CatalogueBarcodeImportStatus $status,
        public ?CatalogueItem $item,
    ) {}
}
