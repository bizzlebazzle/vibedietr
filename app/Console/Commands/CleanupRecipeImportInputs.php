<?php

namespace App\Console\Commands;

use App\Domain\RecipeImports\RecipeImportInputCleaner;
use App\Domain\RecipeImports\RecipeImportStatus;
use App\Models\RecipeImport;
use App\Observability\OperationalTelemetry;
use Illuminate\Console\Command;

final class CleanupRecipeImportInputs extends Command
{
    protected $signature = 'recipe-imports:cleanup-transient';

    protected $description = 'Remove overdue terminal and seven-day abandoned private recipe-import inputs';

    public function handle(RecipeImportInputCleaner $cleaner, OperationalTelemetry $telemetry): int
    {
        $now = now()->utc();
        $terminalCutoff = $now->copy()->subHours((int) config('production.ocr.cleanup_hours', 24));
        $abandonedCutoff = $now->copy()->subDays((int) config('production.ocr.abandoned_days', 7));
        $terminal = [
            RecipeImportStatus::ReviewReady->value,
            RecipeImportStatus::Failed->value,
            RecipeImportStatus::Cancelled->value,
        ];

        $count = 0;
        RecipeImport::query()
            ->whereNotNull('source_key')
            ->where(function ($query) use ($now): void {
                $query->whereNull('processing_lease_until')
                    ->orWhere('processing_lease_until', '<=', $now);
            })
            ->where(function ($query) use ($terminal, $terminalCutoff, $abandonedCutoff): void {
                $query->where(function ($terminalQuery) use ($terminal, $terminalCutoff): void {
                    $terminalQuery->whereIn('status', $terminal)->where('updated_at', '<=', $terminalCutoff);
                })->orWhere(function ($abandonedQuery) use ($terminal, $abandonedCutoff): void {
                    $abandonedQuery->whereNotIn('status', $terminal)->where('source_stored_at', '<=', $abandonedCutoff);
                });
            })
            ->orderBy('id')
            ->chunkById(100, function ($imports) use ($cleaner, $terminal, &$count): void {
                foreach ($imports as $import) {
                    if (! in_array($import->status->value, $terminal, true)) {
                        RecipeImport::query()->whereKey($import->id)->update([
                            'status' => RecipeImportStatus::Failed->value,
                            'failure_category' => 'permanent',
                            'failure_code' => 'abandoned_input_expired',
                            'failed_at' => now()->utc(),
                            'processing_lease_until' => null,
                        ]);
                    }
                    if ($cleaner->cleanup($import->id)) {
                        $count++;
                    }
                }
            });

        $telemetry->counter('recipe_import.abandoned_cleanup', ['outcome' => 'completed'], $count);
        $this->components->info("Cleaned {$count} transient recipe import input(s).");

        return self::SUCCESS;
    }
}
