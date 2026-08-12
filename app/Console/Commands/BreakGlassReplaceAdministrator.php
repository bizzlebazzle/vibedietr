<?php

namespace App\Console\Commands;

use App\Administrator\AdministratorBreakGlassReplacement;
use Illuminate\Console\Command;
use Throwable;

final class BreakGlassReplaceAdministrator extends Command
{
    protected $signature = 'administrator:break-glass-replace';

    protected $description = 'Perform a configured emergency administrator replacement without reopening bootstrap';

    public function handle(AdministratorBreakGlassReplacement $replacement): int
    {
        try {
            $targets = $replacement->targets();
            $this->components->warn('Security-sensitive operation: break-glass administrator replacement');
            $this->line('Environment: '.app()->environment());
            $this->line('Configured replacement: '.$targets['replacement']->email);
            $this->line('Configured compromised account: '.($targets['compromised'] instanceof \App\Models\User ? $targets['compromised']->email : 'none'));

            if (app()->environment('production') && ! $this->input->isInteractive()) {
                $replacement->recordOperatorDeclined();
                $this->components->error('Production break-glass replacement requires interactive operator confirmation.');

                return self::FAILURE;
            }
            if (! $this->confirm('Confirm this exact emergency replacement operation?', false)) {
                $replacement->recordOperatorDeclined();
                $this->components->error('Break-glass replacement was not confirmed.');

                return self::FAILURE;
            }

            $replacement->execute();
            $this->components->info('Break-glass administrator replacement completed.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
