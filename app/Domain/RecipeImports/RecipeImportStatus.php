<?php

namespace App\Domain\RecipeImports;

enum RecipeImportStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case ReviewReady = 'review_ready';
    case Failed = 'failed';
}
