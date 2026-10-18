<?php

namespace App\Console\Commands;

use App\Domain\AccountDeletion\ScopedEvidenceHold;
use App\Observability\Alerts\AlertSink;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class ManageErasureEvidence extends Command
{
    protected $signature = 'erasure:evidence {action : open, review, release, or maintain} {--request= : Protected case JSON file} {--id= : Hold ULID} {--release-authority= : Validated early-release authority reference} {--owner-approved}';

    protected $description = 'Operate separately protected, owner-approved scoped erasure evidence.';

    public function handle(ScopedEvidenceHold $holds): int
    {
        try {
            $action = $this->argument('action');
            if ($action === 'maintain') {
                $result = $holds->maintain();
                if ($result['overdue_reviews'] > 0) {
                    app(AlertSink::class)->send('evidence_hold_review', 'critical', ['count' => $result['overdue_reviews']]);
                    $this->error('Protected evidence review is overdue.');

                    return self::FAILURE;
                }
            } elseif ($action === 'open' && $this->option('owner-approved')) {
                $file = $this->option('request');
                $resolved = is_string($file) ? realpath($file) : false;
                if (! is_string($resolved) || ! is_file($resolved) || filesize($resolved) > 65536
                    || str_starts_with($resolved, base_path().DIRECTORY_SEPARATOR)
                    || (fileperms($resolved) & 0077) !== 0) {
                    throw new RuntimeException;
                }
                $stream = fopen($resolved, 'rb');
                if ($stream === false) {
                    throw new RuntimeException;
                }
                try {
                    $bytes = stream_get_contents($stream, 65537);
                } finally {
                    fclose($stream);
                }
                if (! is_string($bytes) || strlen($bytes) > 65536) {
                    throw new RuntimeException;
                }
                $request = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
                $fields = ['id', 'account_generation', 'category', 'authority_reference', 'owner_approval_reference', 'records', 'expires_at'];
                if (! is_array($request) || array_diff(array_keys($request), $fields) !== []) {
                    throw new RuntimeException;
                }
                $holds->open($request['id'], $request['account_generation'], $request['category'],
                    $request['authority_reference'], $request['owner_approval_reference'], $request['records'],
                    isset($request['expires_at']) ? CarbonImmutable::parse($request['expires_at']) : null);
            } elseif ($action === 'review' && is_string($this->option('id'))) {
                $holds->review($this->option('id'));
            } elseif ($action === 'release' && $this->option('owner-approved') && is_string($this->option('id'))) {
                $holds->release($this->option('id'), $this->option('release-authority'));
            } else {
                throw new RuntimeException;
            }
            $this->info('Protected evidence action completed.');

            return self::SUCCESS;
        } catch (Throwable) {
            // Do not expose case data, ciphertext, SQL bindings or file paths.
            $this->error('Protected evidence action unavailable; verify operator authority, scope and independent-store health.');

            return self::FAILURE;
        }
    }
}
