<?php

namespace App\Domain\RecipeImports;

use App\Models\RecipeImport;
use App\Observability\OperationalTelemetry;
use App\Security\Uploads\CleanupOutcome;
use App\Security\Uploads\TransientInputHandle;
use App\Security\Uploads\TransientInputStore;
use Illuminate\Support\Facades\DB;

final class RecipeImportInputCleaner
{
    public function __construct(
        private readonly TransientInputStore $store,
        private readonly OperationalTelemetry $telemetry,
    ) {}

    public function cleanup(string $importId): bool
    {
        return DB::transaction(function () use ($importId): bool {
            $import = RecipeImport::query()->lockForUpdate()->find($importId);
            if ($import === null || $import->cleanup_completed_at !== null) {
                return true;
            }
            if ($import->processing_lease_until?->isFuture()) {
                return false;
            }

            $outcomes = [];
            if ($import->source_disk !== null && $import->source_key !== null) {
                $outcomes[] = $this->store->cleanup(new TransientInputHandle(
                    $import->source_disk, $import->source_key,
                    (string) $import->source_mime, (int) $import->source_bytes,
                ));
            }
            if ($import->canonical_disk !== null && $import->canonical_key !== null) {
                $outcomes[] = $this->store->cleanup(new TransientInputHandle(
                    $import->canonical_disk, $import->canonical_key,
                    (string) $import->canonical_mime, 0,
                ));
            }
            if (in_array(CleanupOutcome::Failed, $outcomes, true)) {
                $this->telemetry->counter('recipe_import.cleanup', ['outcome' => 'failed']);

                return false;
            }
            $import->forceFill([
                'source_disk' => null, 'source_key' => null,
                'canonical_disk' => null, 'canonical_key' => null,
                'processing_lease_until' => null,
                'cleanup_completed_at' => now()->utc(),
            ])->save();
            $this->telemetry->counter('recipe_import.cleanup', ['outcome' => 'success']);

            return true;
        });
    }
}
