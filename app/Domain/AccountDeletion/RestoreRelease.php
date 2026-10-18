<?php

namespace App\Domain\AccountDeletion;

use App\Models\User;
use App\Operations\DatabaseReconciliation;
use App\Operations\PrivateObjectRecovery;
use App\Operations\RestoreWriteFence;
use App\Security\Notifications\ProductionSecurityReadiness;
use Aws\S3\S3Client;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Reference release protocol. All source/target writers remain externally fenced through cutover. */
final class RestoreRelease
{
    public function prepare(string $directory, ErasureCheckpoint $anchor, S3Client $client): array
    {
        app(PrivacyOperator::class)->reference();
        $context = app(RestoreReleaseCertificate::class)->context();
        $manifest = $this->recoverySet($directory);
        $baseline = json_decode(file_get_contents($directory.'/database.json'), true, flags: JSON_THROW_ON_ERROR);
        app(DatabaseReconciliation::class)->verify(DB::connection(), $baseline, false);
        // The selected set must first match exactly. An interrupted preparation
        // requires a fresh raw target; an old completion marker never substitutes.
        app(ErasureJournalState::class)->at($anchor);
        app(PrivateObjectRecovery::class)->restoreFiltered($client, $this->bucket(), $directory.'/objects', $anchor);
        app(ErasureRestoreReplay::class)->replay($anchor);
        app(RestoreOperationalState::class)->invalidate();
        Cache::forget('audit-retention:cursor');
        do {
            $result = app(PruneAuditRetention::class)->run();
            if ($result['missing_clocks'] !== 0) {
                throw new JournalUnavailable;
            }
        } while (Cache::has('audit-retention:cursor'));
        DB::table('cache')->delete();
        DB::table('cache_locks')->delete();
        $this->readiness();
        $entries = app(ErasureJournalState::class)->at($anchor);
        $holds = app(ScopedEvidenceHold::class)->verificationFingerprint($entries);
        $objects = app(PrivateObjectRecovery::class)->verifyFiltered($client, $this->bucket(), $directory.'/objects', $anchor);
        $fence = app(RestoreWriteFence::class)->fingerprint();
        $proof = ['phase' => 'prepared', 'context' => $context,
            'checkpoint' => ['generation' => $anchor->generation, 'sequence' => $anchor->sequence, 'digest' => $anchor->digest],
            'database' => $this->databaseDigest(), 'write_fence' => $fence, 'objects' => $this->digest($objects), 'holds' => $holds,
            'captured_at' => $manifest['captured_at'], 'verified_at' => now()->utc()->format('Y-m-d\\TH:i:s\\Z')];
        app(ErasureJournalState::class)->at($anchor);

        if (! hash_equals($fence, app(RestoreWriteFence::class)->fingerprint())) {
            throw new JournalUnavailable;
        }

        return app(RestoreReleaseCertificate::class)->sign($proof);
    }

    public function release(string $directory, array $prepared, S3Client $client): array
    {
        app(PrivacyOperator::class)->reference();
        $certificates = app(RestoreReleaseCertificate::class);
        $proof = $certificates->verify($prepared, 'prepared');
        $this->recoverySet($directory);
        $anchor = new ErasureCheckpoint($proof['checkpoint']['generation'], $proof['checkpoint']['sequence'], $proof['checkpoint']['digest']);
        $entries = app(ErasureJournalState::class)->at($anchor);
        $this->assertClocks($entries);
        $this->readiness();
        $objects = app(PrivateObjectRecovery::class)->verifyFiltered($client, $this->bucket(), $directory.'/objects', $anchor);
        if (! hash_equals($proof['objects'], $this->digest($objects))
            || ! hash_equals($proof['database'], $this->databaseDigest())
            || ! hash_equals($proof['holds'], app(ScopedEvidenceHold::class)->verificationFingerprint($entries))) {
            throw new JournalUnavailable;
        }
        app(ErasureJournalState::class)->at($anchor);
        $this->assertClocks($entries);
        if (! hash_equals($proof['write_fence'], app(RestoreWriteFence::class)->fingerprint())) {
            throw new JournalUnavailable;
        }
        $proof['phase'] = 'released';
        $proof['verified_at'] = now()->utc()->format('Y-m-d\\TH:i:s\\Z');

        return $certificates->sign($proof);
    }

    private function recoverySet(string $directory): array
    {
        $context = app(RestoreReleaseCertificate::class)->context();
        $file = $directory.'/manifest.json';
        if (! is_file($file) || is_link($file) || filesize($file) > 8388608
            || ! hash_equals($context['manifest_digest'], hash_file('sha256', $file))) {
            throw new JournalUnavailable;
        }
        $manifest = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        if (($manifest['format'] ?? null) !== 1 || ($manifest['policy'] ?? null) !== 'DEC-012-seven-day'
            || ($manifest['installation'] ?? null) !== config('erasure.installation')
            || ! is_string($manifest['captured_at'] ?? null)) {
            throw new JournalUnavailable;
        }
        $captured = CarbonImmutable::parse($manifest['captured_at'])->utc();
        if ($captured->isFuture() || $captured->addDays(7)->lessThanOrEqualTo(now()->utc())) {
            throw new JournalUnavailable;
        }
        foreach (['database.sql', 'database.json', 'objects/inventory.json'] as $required) {
            if (! isset($manifest['files'][$required])) {
                throw new JournalUnavailable;
            }
        }
        foreach ($manifest['files'] as $path => $expected) {
            if (! is_string($path) || str_contains($path, '..') || ! preg_match('/^[A-Za-z0-9_\/.:-]+$/D', $path)
                || str_starts_with($path, '/') || is_link($directory.'/'.$path) || ! is_file($directory.'/'.$path)
                || filesize($directory.'/'.$path) !== $expected['bytes']
                || ! hash_equals($expected['sha256'], hash_file('sha256', $directory.'/'.$path))) {
                throw new JournalUnavailable;
            }
        }

        return $manifest;
    }

    private function assertClocks(array $entries): void
    {
        foreach (app(ErasureJournalState::class)->latest($entries) as $entry) {
            if ($entry['state'] !== 'recovered' && CarbonImmutable::parse($entry['deadline'])->lessThanOrEqualTo(now()->utc())
                && User::query()->where('account_generation', $entry['account_generation'])->exists()) {
                throw new JournalUnavailable;
            }
        }
    }

    private function readiness(): void
    {
        if (DB::table('erasure_evidence_holds')->exists()) {
            throw new JournalUnavailable;
        }
        $administrators = User::query()->where('is_administrator', true)->whereDoesntHave('accountDeletion')->get();
        if (DB::table('administrator_lifecycle_states')->whereNotNull('bootstrap_completed_at')->exists() && $administrators->isEmpty()) {
            throw new JournalUnavailable;
        }
        if ($administrators->isNotEmpty()) {
            app(ProductionSecurityReadiness::class)->assertReady();
        }
    }

    private function databaseDigest(): string
    {
        return $this->digest(app(DatabaseReconciliation::class)->capture(DB::connection()));
    }

    private function digest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function bucket(): string
    {
        $bucket = config('filesystems.disks.s3.bucket');
        if (! is_string($bucket) || $bucket === '') {
            throw new JournalUnavailable;
        }

        return $bucket;
    }
}
