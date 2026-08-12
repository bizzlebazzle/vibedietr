<?php

namespace App\Console\Commands;

use App\Administrator\AdministratorBootstrap;
use Illuminate\Console\Command;
use Throwable;

final class BootstrapAdministrator extends Command
{
    protected $signature = 'administrator:bootstrap';

    protected $description = 'Perform the one-time, configured administrator bootstrap';

    public function handle(AdministratorBootstrap $bootstrap): int
    {
        $executionStarted = false;

        try {
            $target = $bootstrap->target();
            $this->components->warn('Security-sensitive operation: initial administrator bootstrap');
            $this->line('Environment: '.app()->environment());
            $this->line('Configured target: '.$target->email);

            if (app()->environment('production') && ! $this->input->isInteractive()) {
                $bootstrap->recordOperatorDeclined();
                $this->components->error('Production bootstrap requires interactive operator confirmation.');

                return self::FAILURE;
            }
            if (! $this->confirm('Confirm this exact target and environment for one-time administrator activation?', false)) {
                $bootstrap->recordOperatorDeclined();
                $this->components->error('Administrator bootstrap was not confirmed.');

                return self::FAILURE;
            }

            $executionStarted = true;
            $bootstrap->execute();
            $this->components->info('Administrator bootstrap completed. Disable the bootstrap configuration now.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if (! $executionStarted) {
                $bootstrap->recordConfigurationRefusal($exception);
            }
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
