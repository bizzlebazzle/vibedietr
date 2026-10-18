<?php

namespace App\Domain\AccountDeletion;

use Illuminate\Support\Str;

final class ErasureJournalState
{
    public const ACCOUNT_STATES = ['requested', 'recovered', 'waived', 'under13', 'purge_started', 'purged'];

    /** A release/replay anchor must be current independent evidence, never an app-backup checkpoint.
     * @return list<array<string, mixed>>
     */
    public function at(ErasureCheckpoint $anchor): array
    {
        $journal = app(ProtectedErasureJournal::class);
        $current = $journal->checkpoint();
        if ($current != $anchor) {
            throw new JournalUnavailable;
        }
        $entries = $journal->read($anchor);
        foreach ($entries as $entry) {
            if ($entry['installation'] !== config('erasure.installation')) {
                throw new JournalUnavailable;
            }
            if ($entry['state'] !== 'inventory') {
                continue;
            }
            $transition = $entry['resources'][3] ?? null;
            if (! is_string($transition) || ! str_starts_with($transition, 'transition_')) {
                throw new JournalUnavailable;
            }
            $covered = false;
            foreach ($entries as $root) {
                if ($root['account_generation'] === $entry['account_generation']
                    && $root['state'] === substr($transition, 11) && $root['deadline'] === $entry['deadline']
                    && $root['correlation_id'] === $entry['correlation_id']
                    && ($root['resources'][0] ?? null) === ($entry['resources'][0] ?? null)
                    && ($root['resources'][1] ?? null) === ($entry['resources'][1] ?? null)) {
                    $covered = true;
                    break;
                }
            }
            if (! $covered) {
                throw new JournalUnavailable;
            }
        }
        $expanded = [];
        foreach ($entries as $entry) {
            if ($entry['state'] !== 'inventory') {
                $entry['resources'] = app(JournalManifest::class)->resources($entry, $entries);
                $expanded[] = $entry;
            }
        }
        $this->latest($expanded);

        return $expanded;
    }

    /** @param list<array<string, mixed>> $entries
     * @return array<string, array<string, mixed>>
     */
    public function latest(array $entries): array
    {
        $latest = [];
        foreach ($entries as $entry) {
            if (! in_array($entry['state'], self::ACCOUNT_STATES, true)) {
                continue;
            }
            $id = $this->request($entry);
            $previous = $latest[$entry['account_generation']] ?? null;
            $irreversible = $previous !== null && in_array($previous['state'], ['waived', 'under13', 'purge_started', 'purged'], true);
            if ($previous !== null) {
                if ($id !== $this->request($previous) && ($previous['state'] !== 'recovered' || $entry['state'] !== 'requested')) {
                    throw new JournalUnavailable;
                }
                if ($id === $this->request($previous) && strcmp($entry['deadline'], $previous['deadline']) > 0) {
                    throw new JournalUnavailable;
                }
            }
            if ($previous !== null && $previous['state'] === 'recovered' && $id === $this->request($previous) && $entry['state'] !== 'recovered') {
                throw new JournalUnavailable;
            }
            if ($entry['state'] === 'recovered' && ($irreversible || in_array('policy_under13_no_recovery', $entry['resources'], true))) {
                throw new JournalUnavailable;
            }
            if ($irreversible && $entry['state'] === 'requested') {
                throw new JournalUnavailable;
            }
            if (($previous['under13'] ?? false) || $entry['state'] === 'under13' || in_array('policy_under13_no_recovery', $entry['resources'], true)) {
                $entry['under13'] = true;
                if (! in_array('policy_under13_no_recovery', $entry['resources'], true)) {
                    $entry['resources'][] = 'policy_under13_no_recovery';
                }
            }
            $latest[$entry['account_generation']] = $entry;
        }

        return $latest;
    }

    /** @param array<string, mixed> $entry */
    public function request(array $entry): string
    {
        $refs = array_values(array_filter($entry['resources'], fn ($ref) => str_starts_with($ref, 'request_')));
        if (count($refs) !== 1 || ! Str::isUlid(substr($refs[0], 8))) {
            throw new JournalUnavailable;
        }

        return strtolower(substr($refs[0], 8));
    }
}
