<?php

namespace App\Domain\RecipeImports;

use App\Models\RecipeImport;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RecipeImportOwnerCleaner
{
    public function __construct(private readonly RecipeImportInputCleaner $cleaner) {}

    public function cancelAndCleanup(int $ownerId): void
    {
        $ids = RecipeImport::query()
            ->where('user_id', $ownerId)
            ->where(fn ($query) => $query->whereNotNull('source_key')->orWhereNotNull('canonical_key'))
            ->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id): void {
                $import = RecipeImport::query()->lockForUpdate()->find($id);
                if (! $import) {
                    return;
                }
                if ($import->processing_lease_until?->isFuture()) {
                    throw new RuntimeException('Private import processing must drain before final cleanup.');
                }
                if (! $import->status->terminal()) {
                    $import->forceFill(['status' => RecipeImportStatus::Cancelled,
                        'completed_at' => now()->utc(), 'processing_lease_until' => null])->save();
                }
                if (! $this->cleaner->cleanup((string) $id)) {
                    throw new RuntimeException('Private import cleanup must complete before account deletion.');
                }
            });
        }
    }
}
