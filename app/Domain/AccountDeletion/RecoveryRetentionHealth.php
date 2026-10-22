<?php

namespace App\Domain\AccountDeletion;

use Carbon\CarbonImmutable;

final class RecoveryRetentionHealth
{
    /** Content-free counts; neither a retention extension nor proof of disposal. */
    public function counts(): array
    {
        $journal = app(ProtectedErasureJournal::class);
        $entries = app(ErasureJournalState::class)->at($journal->checkpoint());
        $copies = app(RecoveryCopyCatalog::class)->copies($entries);
        $copyOverdue = 0;
        foreach ($copies as $copy) {
            if (($copy['retired'] ?? false) || CarbonImmutable::parse($copy['deadline'])->addDays(8)->isFuture()) {
                continue;
            }
            if (array_intersect($copy['resources'], ['kind_recovery_set', 'kind_database_replica', 'kind_object_replica', 'kind_host_snapshot']) !== []) {
                $copyOverdue++;
            }
        }
        $journalOverdue = 0;
        foreach (app(ErasureJournalState::class)->latest($entries) as $entry) {
            if ($entry['state'] !== 'recovered' && CarbonImmutable::parse($entry['deadline'])->addDays(9)->lessThanOrEqualTo(now()->utc())) {
                $journalOverdue++;
            }
        }

        return ['copy_expiry_overdue' => $copyOverdue, 'journal_disposal_overdue' => $journalOverdue];
    }
}
