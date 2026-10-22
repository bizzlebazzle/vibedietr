<?php

namespace App\Console\Commands;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Domain\AccountDeletion\AccountLifecycle;
use App\Domain\AccountDeletion\PrivacyOperator;
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
        try {
            $operator = app(PrivacyOperator::class)->reference();
        } catch (\RuntimeException) {
            $this->error('A configured privacy operator and exact environment are required.');

            return self::FAILURE;
        }
        if (! $this->option('confirmed')) {
            $this->error('Prior under-13 confirmation is required.');

            return self::FAILURE;
        }
        $generation = (string) $this->argument('generation');
        if (! Str::isUlid($generation)) {
            $this->error('A valid account generation is required.');

            return self::FAILURE;
        }
        $user = User::query()->where('account_generation', $generation)->firstOrFail();
        $record = $lifecycle->request($user, under13: true);
        app(AuditEventRecorder::class)->record(AuditAction::ErasureOperatorEvent,
            AuditActor::externalOperator($operator), AuditSubject::user($user),
            ['event' => 'under13_confirmed', 'outcome' => 'completed'], $record->correlation_id);
        ProcessAccountDeletion::dispatch($record->id, $record->correlation_id);
        $this->info('Confirmed under-13 account disabled; final purge queued.');

        return self::SUCCESS;
    }
}
