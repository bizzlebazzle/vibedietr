<?php

namespace App\Domain\AccountExports;

use App\Jobs\GenerateAccountExport;
use App\Models\AccountExport;
use App\Models\User;
use App\Queue\CorrelationId;
use Illuminate\Support\Facades\DB;

final class RequestAccountExport
{
    public function request(User $user, ?string $correlationId = null): AccountExport
    {
        $correlationId = CorrelationId::resolve($correlationId);

        return DB::transaction(function () use ($user, $correlationId) {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($lockedUser->isActive(), 403);
            $existing = AccountExport::query()->where('active_key', 'account:'.$user->getKey())->lockForUpdate()->first();
            if ($existing && $existing->expires_at->isFuture() && $existing->state !== 'failed') {
                if ($existing->state === 'queued') {
                    GenerateAccountExport::dispatch($existing->getKey(), $existing->correlation_id);
                }

                return $existing;
            }
            if ($existing) {
                $existing->forceFill(['active_key' => null])->save();
            }
            $export = new AccountExport;
            $export->forceFill([
                'user_id' => $user->getKey(), 'active_key' => 'account:'.$user->getKey(),
                'correlation_id' => $correlationId, 'disk' => config('filesystems.default'),
                'state' => 'queued', 'expires_at' => now()->utc()->addDays(7),
            ])->save();
            app(ExportAudit::class)->record($export, 'requested', $user);
            GenerateAccountExport::dispatch($export->getKey(), $correlationId);

            return $export;
        });
    }
}
