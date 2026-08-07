<?php

namespace App\Queue\Reference;

enum ReferenceTaskOutcome: string
{
    case Completed = 'completed';
    case SkippedMissingTarget = 'skipped_missing_target';
}
