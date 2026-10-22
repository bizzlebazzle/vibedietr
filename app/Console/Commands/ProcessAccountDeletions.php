<?php

namespace App\Console\Commands;

use App\Domain\AccountDeletion\ErasureJournalState;
use App\Domain\AccountDeletion\ProtectedErasureJournal;
use App\Jobs\ProcessAccountDeletion;
use App\Models\AccountDeletion;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class ProcessAccountDeletions extends Command
{
    protected $signature = 'accounts:process-deletions';

    protected $description = 'Queue original-clock lifecycle reconciliation and due account purges.';

    public function handle(): int
    {
        AccountDeletion::query()->where(fn ($query) => $query
            ->whereIn('state', ['preparing', 'recovering', 'waiving', 'purging'])
            ->orWhere('deadline', '<=', now()->utc()))
            ->chunkById(100, function ($requests): void {
                foreach ($requests as $request) {
                    ProcessAccountDeletion::dispatch($request->getKey(), $request->correlation_id);
                }
            });
        $journal = app(ProtectedErasureJournal::class);
        $state = app(ErasureJournalState::class);
        foreach ($state->latest($state->at($journal->repair())) as $intent) {
            if ($intent['state'] !== 'recovered' && $intent['state'] !== 'purged'
                && CarbonImmutable::parse($intent['deadline'])->lessThanOrEqualTo(now()->utc())) {
                ProcessAccountDeletion::dispatch($state->request($intent), $intent['correlation_id']);
            }
        }
        $this->info('Account lifecycle work queued.');

        return self::SUCCESS;
    }
}
