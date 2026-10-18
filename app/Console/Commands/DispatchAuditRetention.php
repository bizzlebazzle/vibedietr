<?php

namespace App\Console\Commands;

use App\Jobs\PruneAccountRetention;
use Illuminate\Console\Command;

final class DispatchAuditRetention extends Command
{
    protected $signature = 'audit:prune-retention';

    protected $description = 'Queue a bounded purpose-specific audit retention sweep.';

    public function handle(): int
    {
        PruneAccountRetention::dispatch();
        $this->info('Audit retention work queued.');

        return self::SUCCESS;
    }
}
