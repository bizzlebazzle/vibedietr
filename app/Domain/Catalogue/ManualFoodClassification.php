<?php

namespace App\Domain\Catalogue;

enum ManualFoodClassification: string
{
    case Generic = 'generic';
    case Branded = 'branded';
}
