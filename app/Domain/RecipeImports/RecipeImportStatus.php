<?php

namespace App\Domain\RecipeImports;

enum RecipeImportStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Fetching = 'fetching';
    case Validating = 'validating';
    case Extracting = 'extracting';
    case ReviewReady = 'review_ready';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function terminal(): bool
    {
        return in_array($this, [self::ReviewReady, self::Failed, self::Cancelled], true);
    }
}
