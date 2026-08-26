<?php

namespace App\Console\Commands;

use App\Configuration\ProductionConfigurationValidator;
use Illuminate\Console\Command;

final class ProductionConfigurationCheck extends Command
{
    protected $signature = 'app:production-check';

    protected $description = 'Validate production configuration without changing application state';

    public function handle(ProductionConfigurationValidator $validator): int
    {
        if (! app()->environment('production')) {
            $this->components->error('APP_ENV must be production to run the production readiness check.');

            return self::FAILURE;
        }

        $failures = $validator->failures();
        if ($failures !== []) {
            $this->components->error('Production configuration is not ready.');
            foreach ($failures as $failure) {
                $this->line(' - '.$failure);
            }

            return self::FAILURE;
        }

        $this->components->info('Production configuration is valid. Live worker, provider, clock, audit, and destination health must also remain monitored.');

        return self::SUCCESS;
    }
}
