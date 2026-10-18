<?php

namespace App\Domain\AccountDeletion;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\Ulid;

/** Prepare fresh encrypted stores first; cutover remains closed until old storage/key copies retire. */
final class JournalGenerationRotation
{
    public function prepare(array $configuration, array $signedInventory, array $signedStorage): array
    {
        app(PrivacyOperator::class)->reference();
        $journal = app(ProtectedErasureJournal::class);
        $oldHead = $journal->checkpoint();
        $state = app(ErasureJournalState::class);
        $entries = $state->at($oldHead, withSequence: true);
        app(RecoveryCopyCatalog::class)->verifyCompleteInventory($signedInventory, $oldHead, $entries);
        $keys = ['journal_connections', 'journal_generation', 'encryption_key', 'integrity_key'];
        $old = array_intersect_key(config('erasure'), array_flip($keys));
        if (array_diff(array_keys($configuration), $keys) !== [] || array_diff($keys, array_keys($configuration)) !== []
            || $configuration['journal_generation'] === $oldHead->generation
            || ! is_array($configuration['journal_connections']) || count($configuration['journal_connections']) !== 2
            || array_intersect($configuration['journal_connections'], $old['journal_connections']) !== []
            || in_array($configuration['encryption_key'], [$old['encryption_key'], $old['integrity_key']], true)
            || in_array($configuration['integrity_key'], [$old['encryption_key'], $old['integrity_key']], true)) {
            throw new JournalUnavailable;
        }
        foreach (['encryption_key', 'integrity_key'] as $name) {
            $nextKey = $this->keyBytes($configuration[$name]);
            if (hash_equals($nextKey, $this->keyBytes($old['encryption_key']))
                || hash_equals($nextKey, $this->keyBytes($old['integrity_key']))) {
                throw new JournalUnavailable;
            }
        }
        $storage = app(ErasureOperationProof::class)->verify($signedStorage, 'fresh-luks2-journal-storage');
        $uuids = $storage['luks2_uuids'] ?? [];
        if (($storage['generation'] ?? null) !== $configuration['journal_generation']
            || ! is_array($uuids) || count($uuids) !== 2 || count(array_unique($uuids)) !== 2
            || ! preg_match('/^[a-f0-9]{64}$/D', $storage['observations_sha256'] ?? '')) {
            throw new JournalUnavailable;
        }
        foreach ($uuids as $uuid) {
            if (! is_string($uuid) || ! preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $uuid)) {
                throw new JournalUnavailable;
            }
        }
        $scopes = [];
        foreach ($configuration['journal_connections'] as $name) {
            $connection = DB::connection($name);
            $scopes[] = hash('sha256', json_encode([$connection->getConfig('host'), $connection->getConfig('port'), $connection->getDatabaseName()], JSON_THROW_ON_ERROR));
        }
        if (($storage['connection_scopes'] ?? null) !== $scopes) {
            throw new JournalUnavailable;
        }
        $retained = $this->retained($entries, $oldHead);
        $clean = array_map(fn ($entry) => array_intersect_key($entry,
            array_flip(['account_generation', 'correlation_id', 'deadline', 'installation', 'policy', 'resources', 'state'])), $retained);
        try {
            foreach ($configuration as $key => $value) {
                config(['erasure.'.$key => $value]);
            }
            // Never append to unrelated data. A retry may contain only the exact
            // deterministic prefix of this same verified rebuild.
            $existing = $journal->read($journal->checkpoint());
            foreach ($clean as $index => $entry) {
                $operation = Ulid::fromBinary(substr(hash('sha256', $oldHead->digest.':'.$configuration['journal_generation'].':'.$index, true), 0, 16))->toBase32();
                app(JournalManifest::class)->append($operation, $entry);
            }
            $newHead = $journal->checkpoint();
            $newEntries = $state->at($newHead);
            if ($newEntries !== $clean || count($existing) > $newHead->sequence) {
                throw new JournalUnavailable;
            }
        } finally {
            foreach ($old as $key => $value) {
                config(['erasure.'.$key => $value]);
            }
        }
        $state->at($oldHead);

        return app(ErasureOperationProof::class)->sign('journal-generation-prepared', [
            'old_checkpoint' => $this->checkpoint($oldHead), 'new_checkpoint' => $this->checkpoint($newHead),
            'retained_manifest_sha256' => hash('sha256', json_encode($clean, JSON_THROW_ON_ERROR)),
            'storage_observations_sha256' => $storage['observations_sha256'],
        ]);
    }

    /** Runs with new-store operator configuration; ordinary writers remain fenced. */
    public function complete(array $prepared, array $signedInventory): array
    {
        app(PrivacyOperator::class)->reference();
        $facts = app(ErasureOperationProof::class)->verify($prepared, 'journal-generation-prepared');
        $journal = app(ProtectedErasureJournal::class);
        $head = $journal->checkpoint();
        $original = $facts['new_checkpoint'];
        if ($head->generation !== $original['generation']) {
            throw new JournalUnavailable;
        }
        $journal->read(new ErasureCheckpoint($original['generation'], $original['sequence'], $original['digest']));
        $raw = $journal->read($head);
        foreach (array_slice($raw, $original['sequence']) as $entry) {
            if ($entry['state'] !== 'copy_retired') {
                throw new JournalUnavailable;
            }
        }
        $entries = app(ErasureJournalState::class)->at($head);
        app(RecoveryCopyCatalog::class)->verifyCompleteInventory($signedInventory, $head, $entries);
        $generationRef = 'generation_'.hash('sha256', $facts['old_checkpoint']['generation']);
        $oldCopies = array_filter(app(RecoveryCopyCatalog::class)->copies($entries),
            fn ($copy) => in_array($generationRef, $copy['resources'], true));
        $kinds = [];
        foreach ($oldCopies as $copy) {
            if (! ($copy['retired'] ?? false)) {
                throw new JournalUnavailable;
            }
            foreach ($copy['resources'] as $ref) {
                if (str_starts_with($ref, 'kind_')) {
                    $kinds[] = substr($ref, 5);
                }
            }
        }
        // Both stores and every backup/header/key/credential copy belong in the
        // signed complete inventory, not just the rows in the primary database.
        if (count(array_filter($kinds, fn ($kind) => $kind === 'journal_storage')) < 2
            || ! in_array('journal_key', $kinds, true) || ! in_array('luks_header', $kinds, true)
            || ! in_array('recovery_credential', $kinds, true)) {
            throw new JournalUnavailable;
        }

        return app(ErasureOperationProof::class)->sign('journal-generation-disposed', [
            'old_generation' => $facts['old_checkpoint']['generation'], 'checkpoint' => $this->checkpoint($head),
            'verified_at' => now()->utc()->format('Y-m-d\\TH:i:s\\Z'),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function retained(array $entries, ErasureCheckpoint $head): array
    {
        $catalog = app(RecoveryCopyCatalog::class);
        $copies = $catalog->copies($entries);
        $active = array_filter($copies, fn ($copy) => ! ($copy['retired'] ?? false));
        $latest = app(ErasureJournalState::class)->latest($entries);
        $holdStates = [];
        $planStates = [];
        foreach ($entries as $entry) {
            if (in_array($entry['state'], ['hold_started', 'hold_released'], true)) {
                $holdStates[$entry['resources'][0]] = $entry;
            } elseif (in_array($entry['state'], ['plan_removed', 'plan_suppressed'], true)) {
                $planStates[$entry['resources'][0]] = $entry;
            }
        }
        $retained = [];
        foreach ($latest as $generation => $entry) {
            $needed = ! in_array($entry['state'], ['purged', 'recovered'], true);
            foreach ($active as $copy) {
                if (! in_array('owner_'.$generation, $copy['resources'], true)) {
                    continue;
                }
                $beforeRecovery = true;
                if (in_array('capture_generation_'.hash('sha256', $head->generation), $copy['resources'], true)) {
                    foreach ($copy['resources'] as $ref) {
                        if (str_starts_with($ref, 'capture_head_')) {
                            $beforeRecovery = (int) substr($ref, 13) < $entry['_sequence'];
                        }
                    }
                }
                if ($entry['state'] === 'purged' || $beforeRecovery || in_array('recovering_'.$generation, $copy['resources'], true)) {
                    $needed = true;
                }
            }
            foreach ($holdStates as $hold) {
                if ($hold['account_generation'] === $generation && $hold['state'] === 'hold_started') {
                    $needed = true;
                }
            }
            if ($needed) {
                $retained[] = $entry;
            }
        }
        foreach ($planStates as $reference => $entry) {
            if ($entry['state'] === 'plan_suppressed' || $this->copied($active, $reference)) {
                $retained[] = $entry;
            }
        }
        foreach ($holdStates as $reference => $entry) {
            if ($entry['state'] === 'hold_started' || $this->copied($active, $reference)
                || $this->copied($active, 'owner_'.$entry['account_generation'])) {
                $retained[] = $entry;
            }
        }
        foreach ($active as $copy) {
            $retained[] = $copy;
        }
        usort($retained, fn ($left, $right) => $left['_sequence'] <=> $right['_sequence']);

        return $retained;
    }

    private function copied(array $copies, string $reference): bool
    {
        foreach ($copies as $copy) {
            if (in_array($reference, $copy['resources'], true)) {
                return true;
            }
        }

        return false;
    }

    private function keyBytes(mixed $value): string
    {
        $key = is_string($value) && str_starts_with($value, 'base64:') ? base64_decode(substr($value, 7), true) : false;
        if (! is_string($key) || strlen($key) !== 32) {
            throw new JournalUnavailable;
        }

        return $key;
    }

    private function checkpoint(ErasureCheckpoint $head): array
    {
        return ['generation' => $head->generation, 'sequence' => $head->sequence, 'digest' => $head->digest];
    }
}
