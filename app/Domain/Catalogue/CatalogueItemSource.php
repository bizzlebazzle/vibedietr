<?php

namespace App\Domain\Catalogue;

enum CatalogueItemSource: string
{
    case Manual = 'manual';
    case OpenFoodFacts = 'openfoodfacts';
}
