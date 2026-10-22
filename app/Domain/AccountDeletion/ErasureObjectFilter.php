<?php

namespace App\Domain\AccountDeletion;

use Carbon\CarbonImmutable;

/** Filters the validated capture inventory independently of any restored SQL rows. */
final class ErasureObjectFilter
{
    public function filter(array $manifest, ErasureCheckpoint $anchor): array
    {
        app(PrivacyOperator::class)->reference();
        if (($manifest['format'] ?? null) !== 1 || ! is_array($manifest['objects'] ?? null)) {
            throw new JournalUnavailable;
        }
        $entries = app(ErasureJournalState::class)->at($anchor);
        $erasedOwners = [];
        $removedResources = [];
        foreach (app(ErasureJournalState::class)->latest($entries) as $generation => $entry) {
            if ($entry['state'] !== 'recovered'
                && ($entry['state'] !== 'requested' || CarbonImmutable::parse($entry['deadline'])->lessThanOrEqualTo(now()->utc()))) {
                $erasedOwners[$generation] = true;
            }
        }
        foreach ($entries as $entry) {
            if ($entry['state'] === 'plan_removed') {
                foreach ($entry['resources'] as $resource) {
                    $removedResources[$resource] = true;
                }
            }
        }
        foreach ($manifest['objects'] as $key => $object) {
            if (! is_string($object['owner_generation'] ?? null)
                || ! preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $object['owner_generation'])
                || ! is_string($object['resource'] ?? null) || $object['resource'] === ''
                || strlen($object['resource']) > 128) {
                throw new JournalUnavailable;
            }
            if (isset($erasedOwners[$object['owner_generation']]) || isset($removedResources[$object['resource']])) {
                unset($manifest['objects'][$key]);
            }
        }
        ksort($manifest['objects'], SORT_STRING);

        return $manifest;
    }
}
