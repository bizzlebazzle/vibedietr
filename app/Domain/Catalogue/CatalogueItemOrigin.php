<?php

namespace App\Domain\Catalogue;

enum CatalogueItemOrigin: string
{
    case Manual = 'manual';
    case Barcode = 'barcode';
}
