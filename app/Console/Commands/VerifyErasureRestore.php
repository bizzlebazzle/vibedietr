<?php

namespace App\Console\Commands;

use App\Domain\AccountDeletion\AccountLifecycle;
use App\Domain\AccountDeletion\ErasureCheckpoint;
use App\Domain\AccountDeletion\PrivacyOperator;
use App\Domain\AccountDeletion\ProtectedErasureJournal;
use App\Domain\AccountDeletion\RestoreRelease;
use App\Models\AccountDeletion;
use App\Operations\RestrictedRecoveryFile;
use Aws\S3\S3Client;
use Illuminate\Console\Command;
use Throwable;

final class VerifyErasureRestore extends Command
{
    protected $signature = 'accounts:verify-restore
        {mode : checkpoint, prepare or release}
        {--directory= : Verified quarantined paired recovery set}
        {--checkpoint= : Current restricted checkpoint from the independent journal}
        {--prepared= : Signed preparation evidence}
        {--write= : New restricted output file outside the application tree}
        {--fenced : Source and target writers, mail, workers, scheduler and public access remain fenced}
        {--approve : Named privacy operator approves release of this verified target}';

    protected $description = 'Verify and sign release of one quarantined restore target; never deploy or change ingress';

    public function handle(RestrictedRecoveryFile $files): int
    {
        try {
            app(PrivacyOperator::class)->reference();
            $mode = $this->argument('mode');
            if (! $this->option('fenced') || ! $this->option('write') || ! in_array($mode, ['checkpoint', 'prepare', 'release'], true)) {
                throw new \RuntimeException('restore_prerequisites_required');
            }
            if ($mode === 'checkpoint') {
                foreach (AccountDeletion::query()->whereIn('state', ['preparing', 'recovering', 'waiving'])->pluck('id') as $id) {
                    app(AccountLifecycle::class)->apply($id, resume: false);
                }
                $anchor = app(ProtectedErasureJournal::class)->repair();
                $result = ['generation' => $anchor->generation, 'sequence' => $anchor->sequence, 'digest' => $anchor->digest];
            } else {
                if (! $this->option('directory')) {
                    throw new \RuntimeException('restore_set_required');
                }
                $disk = config('filesystems.disks.s3');
                $client = new S3Client([
                    'version' => 'latest', 'region' => $disk['region'], 'endpoint' => $disk['endpoint'],
                    'use_path_style_endpoint' => $disk['use_path_style_endpoint'],
                    'credentials' => ['key' => $disk['key'], 'secret' => $disk['secret']],
                    'request_checksum_calculation' => 'when_required', 'http' => ['connect_timeout' => 10, 'timeout' => 120],
                ]);
                if ($mode === 'prepare') {
                    if (! $this->option('checkpoint')) {
                        throw new \RuntimeException('restore_checkpoint_required');
                    }
                    $checkpoint = $files->read((string) $this->option('checkpoint'));
                    $anchor = new ErasureCheckpoint($checkpoint['generation'], $checkpoint['sequence'], $checkpoint['digest']);
                    $result = app(RestoreRelease::class)->prepare((string) $this->option('directory'), $anchor, $client);
                } else {
                    if (! $this->option('approve') || ! $this->option('prepared')) {
                        throw new \RuntimeException('restore_approval_required');
                    }
                    $result = app(RestoreRelease::class)->release((string) $this->option('directory'),
                        $files->read((string) $this->option('prepared')), $client);
                }
            }
            $files->write((string) $this->option('write'), $result);
        } catch (Throwable) {
            $this->error('Restore verification failed. Keep every target fenced; use the restricted operations channel.');

            return self::FAILURE;
        }
        $this->info($mode === 'release' ? 'Verified target release signed. Follow the fenced cutover procedure.' : 'Restricted verification evidence written. The target remains quarantined.');

        return self::SUCCESS;
    }
}
