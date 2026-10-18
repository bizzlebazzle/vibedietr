<?php

namespace App\Console\Commands;

use App\Jobs\DeleteAccountExport;
use App\Models\AccountExport;
use Illuminate\Console\Command;

final class CleanupAccountExports extends Command
{
    protected $signature = 'account-exports:cleanup';

    protected $description = 'Queue expiry and deletion of generated account exports.';

    public function handle(): int
    {
        AccountExport::query()->where('state', '!=', 'deleted')
            ->where(fn ($q) => $q->where('expires_at', '<=', now()->utc())->orWhereNull('user_id'))
            ->chunkById(100, function ($exports): void {
                foreach ($exports as $export) {
                    DeleteAccountExport::dispatch($export->getKey(), $export->correlation_id);
                }
            });
        $this->info('Export cleanup queued.');

        return self::SUCCESS;
    }
}
