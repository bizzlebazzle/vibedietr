<?php

namespace Tests\Concerns;

use App\Domain\AccountDeletion\ErasureJournalSchema;
use Illuminate\Support\Facades\DB;

trait UsesErasureJournal
{
    protected function setUpUsesErasureJournal(): void
    {
        foreach (['lifecycle_journal_primary', 'lifecycle_journal_mirror'] as $name) {
            config(['database.connections.'.$name => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            ]]);
            DB::purge($name);
            app(ErasureJournalSchema::class)->install(DB::connection($name));
        }
        config([
            'erasure.journal_connections' => ['lifecycle_journal_primary', 'lifecycle_journal_mirror'],
            'erasure.journal_generation' => 'synthetic-generation-lifecycle',
            'erasure.installation' => 'synthetic-installation-lifecycle',
            'erasure.encryption_key' => 'base64:'.base64_encode(random_bytes(32)),
            'erasure.integrity_key' => 'base64:'.base64_encode(random_bytes(32)),
        ]);
    }
}
