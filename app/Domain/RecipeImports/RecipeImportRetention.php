<?php

namespace App\Domain\RecipeImports;

use App\Models\RecipeImport;
use App\Observability\OperationalTelemetry;
use Illuminate\Support\Facades\DB;

final class RecipeImportRetention
{
    public function __construct(
        private readonly RecipeImportInputCleaner $cleaner,
        private readonly OperationalTelemetry $telemetry,
    ) {}

    /** @return array{sources: int, metadata: int, invalid_clocks: int, cleanup_failures: int} */
    public function run(): array
    {
        $cleaner = $this->cleaner;
        $telemetry = $this->telemetry;
        $now = now()->utc();
        $count = 0;
        $metadata = 0;
        $invalid = 0;
        $failures = 0;
        RecipeImport::query()->orderBy('id')->chunkById(100, function ($imports) use ($cleaner, $now, &$count, &$metadata, &$invalid, &$failures): void {
            foreach ($imports as $candidate) {
                DB::transaction(function () use ($candidate, $cleaner, $now, &$count, &$metadata, &$invalid, &$failures): void {
                    $import = RecipeImport::query()->lockForUpdate()->find($candidate->id);
                    if ($import === null || $import->processing_lease_until?->greaterThan($now)) {
                        return;
                    }
                    $terminal = $import->status->terminal();
                    $outcome = $import->status === RecipeImportStatus::Failed ? $import->failed_at : $import->completed_at;
                    if ($terminal && ($outcome === null || $outcome->greaterThan($now))) {
                        $invalid++;

                        return;
                    }
                    if (! $terminal) {
                        $stored = $import->source_stored_at ?? $import->created_at;
                        if ($stored === null || $stored->copy()->addDays(7)->greaterThan($now)) {
                            return;
                        }
                        // Late maintenance must not grant a fresh metadata retention period.
                        $outcome = $stored->copy()->addDays(7);
                        $import->forceFill([
                            'status' => RecipeImportStatus::Failed,
                            'failure_category' => 'permanent',
                            'failure_code' => 'abandoned_input_expired',
                            'failed_at' => $outcome,
                            'processing_lease_until' => null,
                        ])->save();
                    }
                    if ($terminal && $outcome->copy()->addHours(24)->greaterThan($now)) {
                        return;
                    }
                    if (! $cleaner->cleanup($import->id)) {
                        $failures++;

                        return;
                    }
                    $count++;
                    if ($outcome->copy()->addDays(30)->lessThanOrEqualTo($now)) {
                        // The draft is independently durable; this FK points from metadata to draft.
                        $import->delete();
                        $metadata++;
                    }
                });
            }
        });
        $telemetry->counter('recipe_import.abandoned_cleanup', ['outcome' => 'completed'], $count);
        $telemetry->counter('recipe_import.metadata_cleanup', ['outcome' => 'completed'], $metadata);
        if ($invalid > 0) {
            $telemetry->counter('recipe_import.retention', ['outcome' => 'invalid_clock'], $invalid);
        }

        return ['sources' => $count, 'metadata' => $metadata, 'invalid_clocks' => $invalid, 'cleanup_failures' => $failures];
    }
}
