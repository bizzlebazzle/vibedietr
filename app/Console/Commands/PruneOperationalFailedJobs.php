<?php

namespace App\Console\Commands;

use App\Observability\Monitoring\OperationalState;
use App\Queue\FailedJobPruner;
use Illuminate\Console\Command;

final class PruneOperationalFailedJobs extends Command
{
    protected $signature = 'queue:prune-operational-failures';

    protected $description = 'Remove terminal personal-payload failures and expired failed-job metadata';

    public function handle(FailedJobPruner $pruner, OperationalState $state): int
    {
        $counts = $pruner->prune();
        $state->recordPrune();
        $this->components->info("Removed {$counts['personal']} personal-payload and {$counts['expired']} expired failed-job record(s).");

        return self::SUCCESS;
    }
}
