<?php

namespace App\Console\Commands;

use App\Domain\AccountDeletion\AccountLifecycle;
use App\Jobs\ProcessAccountDeletion;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class PurgeConfirmedUnder13Account extends Command
{
    protected $signature = 'accounts:purge-under13 {generation} {--confirmed}';

    protected $description = 'Disable a confirmed under-13 account and queue final purge without recovery.';

    public function handle(AccountLifecycle $lifecycle): int
    {
        $operator = config('erasure.privacy_operator');
        if (! $this->option('confirmed') || ! is_string($operator)
            || ! preg_match('/^[A-Za-z0-9:_-]{8,128}$/D', $operator)
            || config('erasure.operator_environment') !== app()->environment()) {
            $this->error('A configured privacy operator, exact environment and prior under-13 confirmation are required.');

            return self::FAILURE;
        }
        $generation = (string) $this->argument('generation');
        if (! Str::isUlid($generation)) {
            $this->error('A valid account generation is required.');

            return self::FAILURE;
        }
        $user = User::query()->where('account_generation', $generation)->firstOrFail();
        $record = $lifecycle->request($user, under13: true);
        ProcessAccountDeletion::dispatch($record->id, $record->correlation_id);
        $this->info('Confirmed under-13 account disabled; final purge queued.');

        return self::SUCCESS;
    }
}
