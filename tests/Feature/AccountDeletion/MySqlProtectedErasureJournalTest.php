<?php

namespace Tests\Feature\AccountDeletion;

/** Same protocol on InnoDB; independent hosts/disk flush still need a drill. */
class MySqlProtectedErasureJournalTest extends ProtectedErasureJournalTest
{
    protected function journalDriver(): string
    {
        return 'mysql';
    }
}
