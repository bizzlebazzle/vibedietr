<?php

namespace App\Domain\RecipeImports;

use App\Models\RecipeImport;
use RuntimeException;

final class RecipeImportOwnerCleaner
{
    public function __construct(private readonly RecipeImportInputCleaner $cleaner) {}

    public function cancelAndCleanup(int $ownerId): void
    {
        $ids = RecipeImport::query()
            ->where('user_id', $ownerId)
            ->whereNotNull('source_key')
            ->pluck('id');

        foreach ($ids as $id) {
            RecipeImport::query()->whereKey($id)->update([
                'status' => RecipeImportStatus::Cancelled->value,
                'processing_lease_until' => null,
                'completed_at' => now()->utc(),
                'updated_at' => now()->utc(),
            ]);
            if (! $this->cleaner->cleanup((string) $id)) {
                throw new RuntimeException('Private import cleanup must complete before account deletion.');
            }
        }
    }
}
