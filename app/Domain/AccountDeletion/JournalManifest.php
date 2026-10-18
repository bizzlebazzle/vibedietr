<?php

namespace App\Domain\AccountDeletion;

use Symfony\Component\Uid\Ulid;

final class JournalManifest
{
    /** @param array<string, mixed> $intent */
    public function append(string $operation, array $intent): ErasureCheckpoint
    {
        $resources = $intent['resources'];
        if (count($resources) <= 400) {
            return app(ProtectedErasureJournal::class)->append($operation, $intent);
        }
        $digest = hash('sha256', json_encode($resources, JSON_THROW_ON_ERROR));
        $parts = array_chunk($resources, 400);
        foreach ($parts as $index => $part) {
            $partIntent = $intent;
            $partIntent['state'] = 'inventory';
            $partIntent['resources'] = [$resources[0], 'manifest_'.$digest, 'part_'.str_pad((string) $index, 16, '0', STR_PAD_LEFT), 'transition_'.$intent['state'], ...$part];
            $partOperation = Ulid::fromBinary(substr(hash('sha256', $operation.':'.$index, true), 0, 16))->toBase32();
            app(ProtectedErasureJournal::class)->append($partOperation, $partIntent);
        }
        $intent['resources'] = [$resources[0], 'manifest_'.$digest, 'parts_'.str_pad((string) count($parts), 16, '0', STR_PAD_LEFT)];

        return app(ProtectedErasureJournal::class)->append($operation, $intent);
    }

    /** @param array<string, mixed> $intent
     * @param  list<array<string, mixed>>  $entries
     * @return list<string>
     */
    public function resources(array $intent, array $entries): array
    {
        if (! isset($intent['resources'][1]) || ! str_starts_with($intent['resources'][1], 'manifest_')) {
            return $intent['resources'];
        }
        $parts = [];
        foreach ($entries as $entry) {
            if ($entry['state'] !== 'inventory' || $entry['account_generation'] !== $intent['account_generation']
                || ($entry['resources'][0] ?? null) !== $intent['resources'][0]
                || ($entry['resources'][1] ?? null) !== $intent['resources'][1]) {
                continue;
            }
            $index = (int) substr($entry['resources'][2], 5);
            $offset = isset($entry['resources'][3]) && str_starts_with($entry['resources'][3], 'transition_') ? 4 : 3;
            if ($offset === 4 && $entry['resources'][3] !== 'transition_'.$intent['state']) {
                continue;
            }
            $value = array_slice($entry['resources'], $offset);
            if (isset($parts[$index]) && $parts[$index] !== $value) {
                throw new JournalUnavailable;
            }
            $parts[$index] = $value;
        }
        ksort($parts);
        $count = (int) substr($intent['resources'][2], 6);
        if ($count < 1 || array_keys($parts) !== range(0, $count - 1)) {
            throw new JournalUnavailable;
        }
        $resources = array_merge(...array_values($parts));
        if (! hash_equals(substr($intent['resources'][1], 9), hash('sha256', json_encode($resources, JSON_THROW_ON_ERROR)))) {
            throw new JournalUnavailable;
        }

        return $resources;
    }
}
