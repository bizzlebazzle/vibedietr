<?php

namespace App\Domain\Catalogue;

enum CatalogueBarcodeImportStatus: string
{
    case Created = 'created';
    case Reused = 'reused';
    case Unavailable = 'unavailable';
}
