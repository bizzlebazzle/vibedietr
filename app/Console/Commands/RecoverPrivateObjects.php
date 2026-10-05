<?php

namespace App\Console\Commands;

use App\Operations\PrivateObjectRecovery;
use Aws\S3\S3Client;
use Illuminate\Console\Command;
use Throwable;

final class RecoverPrivateObjects extends Command
{
    protected $signature = 'operations:recovery-objects
        {mode : capture, restore or verify}
        {directory : Restricted local object staging directory}
        {--ownership= : JSON key-to-account-generation/resource inventory for capture}
        {--exclude=* : Additional reviewed transient/export prefixes, ending in /}
        {--quiesced : Assert all writers are stopped}
        {--isolated-empty-target : Assert restoration target is private and quarantined}';

    protected $description = 'Capture or verify private S3 objects; restore only into an empty quarantined bucket';

    public function handle(PrivateObjectRecovery $recovery): int
    {
        $mode = (string) $this->argument('mode');
        if (! $this->option('quiesced') || ! in_array($mode, ['capture', 'restore', 'verify'], true) || ($mode === 'restore' && ! $this->option('isolated-empty-target')) || ($mode === 'capture' && ! $this->option('ownership'))) {
            $this->error('Recovery requires an explicit frozen source or isolated frozen target and complete ownership inventory.');

            return self::FAILURE;
        }
        try {
            $disk = config('filesystems.disks.s3');
            $client = new S3Client([
                'version' => 'latest', 'region' => $disk['region'],
                'endpoint' => $disk['endpoint'], 'use_path_style_endpoint' => $disk['use_path_style_endpoint'],
                'credentials' => ['key' => $disk['key'], 'secret' => $disk['secret']],
                'request_checksum_calculation' => 'when_required',
                'http' => ['connect_timeout' => 10, 'timeout' => 120],
            ]);
            $directory = (string) $this->argument('directory');
            if ($mode === 'capture') {
                $ownership = json_decode(file_get_contents((string) $this->option('ownership')), true, flags: JSON_THROW_ON_ERROR);
                $excluded = array_unique([trim((string) config('security.uploads.prefix'), '/').'/', 'canonical/', ...$this->option('exclude')]);
                $recovery->capture($client, $disk['bucket'], $directory, $ownership, array_values($excluded));
            } elseif ($mode === 'restore') {
                $recovery->restore($client, $disk['bucket'], $directory);
            } else {
                $recovery->verify($client, $disk['bucket'], $directory);
            }
        } catch (Throwable) {
            $this->error('Private-object recovery failed; keep the target closed. Inspect only through the restricted operations channel.');

            return self::FAILURE;
        }
        $this->info('Private-object operation passed. Quarantine remains required; this does not authorize release.');

        return self::SUCCESS;
    }
}
