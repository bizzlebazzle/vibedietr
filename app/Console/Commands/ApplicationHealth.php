<?php

namespace App\Console\Commands;

use App\Observability\Health\HealthService;
use Illuminate\Console\Command;

final class ApplicationHealth extends Command
{
    protected $signature = 'app:health';

    protected $description = 'Report safe runtime dependency health';

    public function handle(HealthService $health): int
    {
        $report = $health->readiness();
        $this->line('Application readiness: '.$report['status']);
        foreach ($report['checks'] as $check) {
            $this->line(sprintf('%s: %s (%s)', $check['name'], $check['state'], $check['reason']));
        }

        return $report['status'] === 'unhealthy' ? self::FAILURE : self::SUCCESS;
    }
}
