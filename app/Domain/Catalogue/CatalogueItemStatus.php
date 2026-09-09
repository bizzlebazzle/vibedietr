<?php

namespace App\Domain\Catalogue;

enum CatalogueItemStatus: string
{
    case Merged = 'merged';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
