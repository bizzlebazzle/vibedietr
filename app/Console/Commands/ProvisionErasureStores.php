<?php

namespace App\Console\Commands;

use App\Domain\AccountDeletion\ErasureEvidenceSchema;
use App\Domain\AccountDeletion\ErasureJournalSchema;
use App\Domain\AccountDeletion\PrivacyOperator;
use App\Domain\AccountDeletion\ProtectedErasureJournal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class ProvisionErasureStores extends Command
{
    protected $signature = 'erasure:provision {store=journal : journal, next-journal or evidence}';

    protected $description = 'Add erasure schemas on explicitly configured dedicated stores.';

    public function handle(PrivacyOperator $operator): int
    {
        try {
            $operator->reference();
            $evidence = $this->argument('store') === 'evidence';
            if (! in_array($this->argument('store'), ['journal', 'next-journal', 'evidence'], true)) {
                throw new RuntimeException;
            }
            $next = $this->argument('store') === 'next-journal';
            $names = $evidence ? ['erasure_evidence'] : ($next ? ['erasure_next_primary', 'erasure_next_mirror'] : config('erasure.journal_connections'));
            if (! is_array($names) || $names === []) {
                throw new RuntimeException;
            }
            foreach ($names as $name) {
                $db = DB::connection($name);
                if ($db === DB::connection() || (! app()->environment('testing')
                    && ($db->getDriverName() !== 'mysql' || $db->getConfig('host') === DB::connection()->getConfig('host')))) {
                    throw new RuntimeException;
                }
                $table = $evidence ? 'erasure_evidence_holds' : 'erasure_journal_entries';
                if ($next && $db->getSchemaBuilder()->hasTable($table) && $db->table($table)->exists()) {
                    throw new RuntimeException;
                }
                if (! $db->getSchemaBuilder()->hasTable($table)) {
                    $evidence ? app(ErasureEvidenceSchema::class)->install($db) : app(ErasureJournalSchema::class)->install($db);
                }
            }
            if (! $evidence && ! $next) {
                app(ProtectedErasureJournal::class)->repair();
            }
            $this->info('Dedicated erasure schema ready. Verify independent storage, TLS, key custody and copy inventory before activation.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Dedicated erasure provisioning unavailable; verify operator scope and store configuration.');

            return self::FAILURE;
        }
    }
}
