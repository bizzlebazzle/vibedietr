<?php

namespace App\Console\Commands;

use App\Jobs\ProcessAccountDeletion;
use App\Models\AccountDeletion;
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
        $this->info('Account lifecycle work queued.');

        return self::SUCCESS;
    }
}
