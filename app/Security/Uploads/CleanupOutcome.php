<?php

namespace App\Security\Uploads;

enum CleanupOutcome: string
{
    case Deleted = 'deleted';
    case Missing = 'missing';
    case Failed = 'failed';
}
