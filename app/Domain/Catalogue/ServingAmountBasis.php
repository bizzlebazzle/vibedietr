<?php

namespace App\Domain\Catalogue;

enum ServingAmountBasis: string
{
    case Source = 'source';
    case AmountPerItemDividedByServingsPerItem = 'derived_amount_per_item_divided_by_servings_per_item';
}
