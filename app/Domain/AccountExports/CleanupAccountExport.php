<?php

namespace App\Domain\AccountExports;

use App\Models\AccountExport;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CleanupAccountExport
{
    public function cleanup(string $id): void
    {
        DB::transaction(function () use ($id): void {
            $export = AccountExport::query()->lockForUpdate()->find($id);
            if (! $export || $export->state === 'deleted' || ($export->user_id !== null && $export->expires_at->isFuture())) {
                return;
            }
            if ($export->state !== 'expired') {
                $export->forceFill(['state' => 'expired', 'active_key' => null])->save();
                app(ExportAudit::class)->record($export, 'expired');
            }
        });
        DB::transaction(function () use ($id): void {
            $export = AccountExport::query()->lockForUpdate()->find($id);
            if (! $export || $export->state !== 'expired') {
                return;
            }
            $disk = app(ExportStorage::class)->disk($export->disk);
            if ($disk->exists($export->storageKey()) && (! $disk->delete($export->storageKey()) || app(ExportStorage::class)->disk($export->disk)->exists($export->storageKey()))) {
                throw new RuntimeException('export_cleanup_failed');
            }
            if (is_file($export->stagingPath()) && ! unlink($export->stagingPath())) {
                throw new RuntimeException('export_staging_cleanup_failed');
            }
            $export->forceFill(['state' => 'deleted', 'deleted_at' => now()->utc()])->save();
            app(ExportAudit::class)->record($export, 'deleted');
        });
    }
}
