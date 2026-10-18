<?php

namespace App\Domain\AccountDeletion;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Symfony\Component\Uid\Ulid;

/** Protected inventory of physical copies/layers, including staging, keys and headers. */
final class RecoveryCopyCatalog
{
    public const KINDS = ['recovery_set', 'database_replica', 'object_replica', 'host_snapshot', 'staging_workspace',
        'restore_target', 'journal_storage', 'journal_backup', 'journal_key', 'luks_header', 'recovery_credential'];

    public function register(string $id, string $kind, CarbonImmutable $captured, string $manifest, array $references, ErasureCheckpoint $coverage): void
    {
        app(PrivacyOperator::class)->reference();
        if (! Str::isUlid($id) || ! in_array($kind, self::KINDS, true) || $captured->isFuture()
            || ! preg_match('/^[a-f0-9]{64}$/D', $manifest) || ! array_is_list($references)) {
            throw new JournalUnavailable;
        }
        app(ErasureJournalState::class)->at($coverage);
        foreach ($references as $reference) {
            if (! is_string($reference) || ! preg_match('/^(owner|recovering|plan|recipe|parent|generation|hold)_[A-Za-z0-9_-]{16,110}$/D', $reference)) {
                throw new JournalUnavailable;
            }
        }
        $entries = app(ErasureJournalState::class)->at($coverage);
        $copies = $this->copies($entries);
        foreach ($references as $reference) {
            if (str_starts_with($reference, 'parent_')) {
                $parent = $copies[strtoupper(substr($reference, 7))] ?? null;
                if (! $parent || ($parent['retired'] ?? false) || CarbonImmutable::parse($parent['deadline'])->lessThan($captured)) {
                    // Copying cannot renew the source capture clock.
                    throw new JournalUnavailable;
                }
                $references = array_values(array_unique([...$references, ...$parent['resources']]));
            }
        }
        $references = array_values(array_filter($references, fn ($ref) => preg_match('/^(owner|recovering|plan|recipe|parent|generation|hold)_/', $ref)));
        if (in_array($kind, ['journal_storage', 'journal_backup', 'journal_key', 'luks_header', 'recovery_credential'], true)
            && (array_filter($references, fn ($ref) => str_starts_with($ref, 'generation_')) === []
                || array_filter($references, fn ($ref) => preg_match('/^(owner|recovering|plan|recipe|hold)_/', $ref)) !== [])) {
            throw new JournalUnavailable;
        }
        foreach (app(ErasureJournalState::class)->latest($entries) as $generation => $entry) {
            if ($entry['state'] !== 'recovered' && in_array('owner_'.$generation, $references, true)
                && CarbonImmutable::parse($entry['deadline'])->lessThanOrEqualTo($captured)) {
                $references[] = 'incident_dirty_capture';
            }
        }
        $intent = $this->intent($id, 'copy_registered', $captured, [
            'copy_'.strtoupper($id), 'kind_'.$kind, 'manifest_'.$manifest,
            'capture_head_'.str_pad((string) $coverage->sequence, 16, '0', STR_PAD_LEFT), 'coverage_'.$coverage->digest, 'capture_generation_'.hash('sha256', $coverage->generation),
            ...array_values(array_unique($references)),
        ]);
        app(JournalManifest::class)->append($this->operation($id, 'registered'), $intent);
    }

    public function retire(array $signedObservation): void
    {
        app(PrivacyOperator::class)->reference();
        $facts = app(ErasureOperationProof::class)->verify($signedObservation, 'copy-retirement');
        $id = strtoupper($facts['copy_id'] ?? '');
        $journal = app(ProtectedErasureJournal::class);
        $copies = $this->copies(app(ErasureJournalState::class)->at($journal->checkpoint()));
        $copy = $copies[$id] ?? null;
        if (! is_string($facts['verified_at'] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $facts['verified_at'])) {
            throw new JournalUnavailable;
        }
        $verified = CarbonImmutable::parse($facts['verified_at']);
        $mechanism = $facts['mechanism'] ?? '';
        if (! $copy || ! in_array('manifest_'.($facts['manifest_sha256'] ?? ''), $copy['resources'], true)
            || ! preg_match('/^[a-f0-9]{64}$/D', $facts['observations_sha256'] ?? '')
            || $verified->isFuture() || $verified->lessThan(CarbonImmutable::parse($copy['deadline']))
            || ! in_array($mechanism, ['restic_forget_prune_readcheck', 'luks2_generation_and_all_keys', 'synthetic_volatile_generation'], true)
            || ($mechanism === 'synthetic_volatile_generation' && ! app()->environment('testing'))) {
            throw new JournalUnavailable;
        }
        if ($mechanism === 'restic_forget_prune_readcheck' && ! in_array('kind_recovery_set', $copy['resources'], true)) {
            throw new JournalUnavailable;
        }
        // A signed observation must cover every registered physical sub-layer.
        $expected = array_values(array_filter($copy['resources'], fn ($ref) => str_starts_with($ref, 'generation_')));
        $observed = $facts['generations'] ?? null;
        if (! is_array($observed)) {
            throw new JournalUnavailable;
        }
        sort($expected);
        sort($observed);
        if ($expected !== $observed) {
            throw new JournalUnavailable;
        }
        app(ProtectedErasureJournal::class)->append($this->operation($id, 'retired'),
            $this->intent($id, 'copy_retired', $verified, ['copy_'.$id, 'verification_'.$facts['observations_sha256'], 'mechanism_'.$mechanism]));
    }

    /** Latest registered copy plus a verified retirement marker; unknown copies are never inferred absent. */
    public function copies(array $entries): array
    {
        $copies = [];
        foreach ($entries as $entry) {
            if (! in_array($entry['state'], ['copy_registered', 'copy_retired'], true)) {
                continue;
            }
            $id = substr($entry['account_generation'], 5);
            if (! Str::isUlid($id)) {
                throw new JournalUnavailable;
            }
            if ($entry['state'] === 'copy_registered') {
                if (isset($copies[$id]) && $copies[$id] !== $entry) {
                    throw new JournalUnavailable;
                }
                $copies[$id] = $entry;
            } else {
                if (! isset($copies[$id])) {
                    throw new JournalUnavailable;
                }
                $copies[$id]['retired'] = true;
            }
        }

        return $copies;
    }

    public function verifyCompleteInventory(array $signedInventory, ErasureCheckpoint $head, array $entries): void
    {
        $facts = app(ErasureOperationProof::class)->verify($signedInventory, 'complete-copy-inventory');
        $ids = array_keys(array_filter($this->copies($entries), fn ($copy) => ! ($copy['retired'] ?? false)));
        sort($ids);
        $reported = $facts['active_copy_ids'] ?? null;
        if (! is_array($reported)) {
            throw new JournalUnavailable;
        }
        sort($reported);
        if ($reported !== $ids || ($facts['scope'] ?? null) !== 'all-data-journal-key-header-credential-and-host-layers'
            || ($facts['checkpoint'] ?? null) !== ['generation' => $head->generation, 'sequence' => $head->sequence, 'digest' => $head->digest]
            || ! preg_match('/^[a-f0-9]{64}$/D', $facts['observations_sha256'] ?? '')) {
            throw new JournalUnavailable;
        }
    }

    private function intent(string $id, string $state, CarbonImmutable $clock, array $references): array
    {
        return ['account_generation' => 'copy_'.strtoupper($id), 'installation' => config('erasure.installation'),
            'correlation_id' => strtoupper($id), 'deadline' => $clock->utc()->format('Y-m-d\\TH:i:s\\Z'),
            'state' => $state, 'policy' => RestoreReleaseCertificate::POLICY, 'resources' => $references];
    }

    private function operation(string $id, string $event): string
    {
        return Ulid::fromBinary(substr(hash('sha256', 'copy:'.strtoupper($id).':'.$event, true), 0, 16))->toBase32();
    }
}
