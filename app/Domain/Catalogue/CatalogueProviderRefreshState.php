<?php

namespace App\Domain\Catalogue;

enum CatalogueProviderRefreshState: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Staged = 'staged';
    case NoChange = 'no_change';
    case NotFound = 'not_found';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    public function isTerminal(): bool
    {
        return ! in_array($this, [self::Queued, self::Processing, self::Staged], true);
    }
}
