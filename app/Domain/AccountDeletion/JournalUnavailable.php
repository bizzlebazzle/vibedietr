<?php

namespace App\Domain\AccountDeletion;

use RuntimeException;

final class JournalUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('erasure_journal_unavailable');
    }
}
