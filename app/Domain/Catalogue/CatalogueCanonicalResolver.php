<?php

namespace App\Domain\Catalogue;

use App\Models\CatalogueItem;

final class CatalogueCanonicalResolver
{
    public function resolve(CatalogueItem $item, bool $lock = false): ?CatalogueItem
    {
        if ($item->status !== CatalogueItemStatus::Merged) {
            return $item;
        }
        $query = CatalogueItem::query()->whereKey($item->canonical_catalogue_item_id)->where('status', CatalogueItemStatus::Approved);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }
}
