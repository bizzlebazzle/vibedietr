<?php

namespace App\Console\Commands;

use App\Observability\Monitoring\OperationalState;
use Illuminate\Console\Command;

final class RecordSchedulerHeartbeat extends Command
{
    protected $signature = 'observability:scheduler-heartbeat';

    protected $description = 'Record proof that the Laravel scheduler is running';

    public function handle(OperationalState $state): int
    {
        $state->recordScheduler();

        return self::SUCCESS;
    }
}
