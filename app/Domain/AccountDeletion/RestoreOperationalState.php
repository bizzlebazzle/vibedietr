<?php

namespace App\Domain\AccountDeletion;

use App\Domain\AccountExports\CleanupAccountExport;
use App\Domain\RecipeImports\RecipeImportInputCleaner;
use App\Domain\RecipeImports\RecipeImportRetention;
use App\Domain\RecipeImports\RecipeImportStatus;
use App\Models\AccountExport;
use App\Models\RecipeImport;
use Illuminate\Support\Facades\DB;

/** Runs only against the isolated restore target; never resumes old work. */
final class RestoreOperationalState
{
    public function invalidate(): void
    {
        app(PrivacyOperator::class)->reference();
        foreach (['session.connection', 'cache.stores.database.connection', 'cache.stores.database.lock_connection', 'queue.connections.database.connection'] as $key) {
            if (config($key) !== null && config($key) !== config('database.default')) {
                throw new JournalUnavailable;
            }
        }
        foreach (AccountExport::query()->where('state', '!=', 'deleted')->cursor() as $export) {
            $export->forceFill(['state' => 'expired', 'active_key' => null])->save();
            app(CleanupAccountExport::class)->cleanup($export->id);
            if ($export->fresh()?->state !== 'deleted') {
                throw new JournalUnavailable;
            }
        }
        foreach (RecipeImport::query()->cursor() as $import) {
            $import->forceFill(['processing_lease_until' => null])->save();
            if (! $import->status->terminal()) {
                $expiry = ($import->source_stored_at ?? $import->created_at)->copy()->addDays(7);
                $expired = $expiry->lessThanOrEqualTo(now()->utc());
                $import->forceFill($expired
                    ? ['status' => RecipeImportStatus::Failed, 'failed_at' => $expiry,
                        'failure_category' => 'permanent', 'failure_code' => 'abandoned_input_expired']
                    : ['status' => RecipeImportStatus::Cancelled, 'completed_at' => now()->utc()])->save();
            }
            if ($import->source_key !== null || $import->canonical_key !== null) {
                // A restored completion marker cannot bypass physical verification.
                $import->forceFill(['processing_lease_until' => null, 'cleanup_completed_at' => null])->save();
                if (! app(RecipeImportInputCleaner::class)->cleanup($import->id)) {
                    throw new JournalUnavailable;
                }
            }
        }
        $retention = app(RecipeImportRetention::class)->run();
        if ($retention['invalid_clocks'] !== 0 || $retention['cleanup_failures'] !== 0) {
            throw new JournalUnavailable;
        }
        DB::transaction(function (): void {
            foreach (['sessions', 'password_reset_tokens', 'jobs', 'failed_jobs', 'job_batches', 'cache', 'cache_locks'] as $table) {
                DB::table($table)->delete();
            }
            DB::table('users')->whereNotNull('remember_token')->update(['remember_token' => null]);
            DB::table('second_factor_enrollments')->delete();
            DB::table('second_factor_recovery_authorizations')->whereNull('consumed_at')->whereNull('cancelled_at')
                ->update(['cancelled_at' => now()->utc(), 'authorization_hash' => null]);
            DB::table('administrator_promotion_requests')->where('status', 'pending')
                ->update(['status' => 'cancelled', 'cancelled_at' => now()->utc()]);
            DB::table('security_notification_intents')->whereIn('status', ['created', 'queued', 'processing', 'deferred'])
                ->update(['status' => 'permanently_rejected', 'failure_code' => 'restore_state_invalidated', 'terminal_at' => now()->utc()]);
        });
    }
}
