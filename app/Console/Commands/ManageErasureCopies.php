<?php

namespace App\Console\Commands;

use App\Domain\AccountDeletion\JournalGenerationRotation;
use App\Domain\AccountDeletion\PrivacyOperator;
use App\Domain\AccountDeletion\ProtectedErasureJournal;
use App\Domain\AccountDeletion\RecoveryCopyCatalog;
use App\Domain\AccountDeletion\RecoveryRetentionHealth;
use App\Operations\RestrictedRecoveryFile;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

final class ManageErasureCopies extends Command
{
    protected $signature = 'erasure:copies
        {mode : register, retire, monitor, new-generation, prepare-generation or complete-generation}
        {--request= : Restricted owner-approved copy registration or new-store configuration}
        {--evidence= : Separately signed backend observation or complete inventory}
        {--storage= : Separately signed fresh LUKS2 storage observation}
        {--prepared= : Signed generation preparation ticket}
        {--write= : New restricted output path}
        {--fenced : All old/new journal and application writers remain fenced}
        {--owner-approved : Controller approved the complete scope and observations}';

    protected $description = 'Inventory recovery copies and verify journal-generation retirement; no SQL-row disposal shortcut';

    public function handle(RestrictedRecoveryFile $files): int
    {
        try {
            app(PrivacyOperator::class)->reference();
            $mode = $this->argument('mode');
            if ($mode === 'monitor') {
                $counts = app(RecoveryRetentionHealth::class)->counts();
                $this->line(json_encode($counts, JSON_THROW_ON_ERROR));

                return array_sum($counts) === 0 ? self::SUCCESS : self::FAILURE;
            }
            if (! $this->option('owner-approved') || ! $this->option('fenced')) {
                throw new \RuntimeException('copy_inventory_approval_required');
            }
            if ($mode === 'new-generation') {
                $files->write((string) $this->option('write'), [
                    'journal_connections' => ['erasure_next_primary', 'erasure_next_mirror'],
                    'journal_generation' => (string) Str::ulid(),
                    'encryption_key' => 'base64:'.base64_encode(random_bytes(32)),
                    'integrity_key' => 'base64:'.base64_encode(random_bytes(32)),
                ]);
            } elseif ($mode === 'register') {
                $request = $files->read((string) $this->option('request'));
                app(RecoveryCopyCatalog::class)->register($request['id'], $request['kind'],
                    CarbonImmutable::parse($request['captured_at']), $request['manifest_sha256'], $request['references'],
                    app(ProtectedErasureJournal::class)->checkpoint());
            } elseif ($mode === 'retire') {
                app(RecoveryCopyCatalog::class)->retire($files->read((string) $this->option('evidence')));
            } elseif ($mode === 'prepare-generation') {
                $result = app(JournalGenerationRotation::class)->prepare(
                    $files->read((string) $this->option('request')), $files->read((string) $this->option('evidence')),
                    $files->read((string) $this->option('storage')));
                $files->write((string) $this->option('write'), $result);
            } elseif ($mode === 'complete-generation') {
                $result = app(JournalGenerationRotation::class)->complete(
                    $files->read((string) $this->option('prepared')), $files->read((string) $this->option('evidence')));
                $files->write((string) $this->option('write'), $result);
            } else {
                throw new \RuntimeException('copy_inventory_mode_invalid');
            }
        } catch (Throwable) {
            $this->error('Copy verification failed. Preserve required journal truth, keep targets fenced and record an unresolved retention incident.');

            return self::FAILURE;
        }
        $this->info('Protected copy operation recorded. A rebuild alone does not prove old storage/key disposal.');

        return self::SUCCESS;
    }
}
