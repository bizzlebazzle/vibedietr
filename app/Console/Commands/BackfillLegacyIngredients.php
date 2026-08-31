<?php

namespace App\Console\Commands;

use App\Domain\Catalogue\LegacyIngredientBackfill;
use App\Domain\Catalogue\LegacyIngredientClassification;
use App\Domain\Catalogue\LegacyIngredientClassifier;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class BackfillLegacyIngredients extends Command
{
    protected $signature = 'catalogue:backfill-legacy-ingredients
        {--dry-run : Classify and reconcile without writing}
        {--chunk=500 : Number of legacy rows read per batch}';

    protected $description = 'Copy and classify legacy ingredients as pending catalogue candidates';

    public function handle(
        LegacyIngredientClassifier $classifier,
        LegacyIngredientBackfill $backfill,
    ): int {
        $chunkSize = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);

        if ($chunkSize === false || $chunkSize < 1 || $chunkSize > 5000) {
            $this->components->error('The --chunk value must be an integer between 1 and 5000.');

            return self::INVALID;
        }

        $lock = Cache::lock('catalogue:backfill-legacy-ingredients', 3600);

        if (! $lock->get()) {
            $this->components->error('Another legacy ingredient backfill is already running.');

            return self::FAILURE;
        }

        try {
            return $this->runBackfill($classifier, $backfill, $chunkSize);
        } finally {
            $lock->release();
        }
    }

    private function runBackfill(
        LegacyIngredientClassifier $classifier,
        LegacyIngredientBackfill $backfill,
        int $chunkSize,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $highWaterMark = (int) (DB::table('ingredients')->max('id') ?? 0);
        $eligible = DB::table('ingredients')->where('id', '<=', $highWaterMark)->count();
        $mappedBefore = $this->mappedCount($highWaterMark);
        $counts = array_fill_keys(array_map(
            fn (LegacyIngredientClassification $classification): string => $classification->value,
            LegacyIngredientClassification::cases(),
        ), 0);
        $newlyProcessed = 0;
        $failed = 0;
        $sourceChanged = 0;
        $visited = 0;
        $unexpectedFailure = false;

        if ($dryRun) {
            $this->components->info('Dry run: no catalogue or mapping rows will be written.');
        }

        try {
            $this->eligibleQuery($highWaterMark)->chunkById(
                $chunkSize,
                function ($rows) use (
                    $classifier,
                    $backfill,
                    $dryRun,
                    &$counts,
                    &$newlyProcessed,
                    &$failed,
                    &$sourceChanged,
                    &$visited,
                ): void {
                    foreach ($rows as $ingredient) {
                        $visited++;
                        $classification = $classifier->classify(
                            $ingredient,
                            (bool) $ingredient->has_duplicate_barcode,
                        );
                        $counts[$classification->classification->value]++;

                        if ($ingredient->mapping_id !== null) {
                            if (! hash_equals(
                                (string) $ingredient->mapping_checksum,
                                $backfill->checksum($ingredient),
                            )) {
                                $sourceChanged++;
                                $failed++;
                            }

                            continue;
                        }

                        if ($dryRun) {
                            $newlyProcessed++;

                            continue;
                        }

                        $backfill->persist($ingredient, $classification);
                        $newlyProcessed++;
                    }
                },
                'ingredients.id',
                'id',
            );
        } catch (Throwable) {
            $failed++;
            $unexpectedFailure = true;
            $this->components->error('Unexpected failure while processing the bounded legacy ingredient range.');
        }

        $totalMappedAfter = $dryRun
            ? $mappedBefore + $newlyProcessed
            : $this->mappedCount($highWaterMark);
        $unprocessed = max(0, $eligible - $visited);

        $this->table(['Measure', 'Count'], [
            ['Eligible legacy rows', $eligible],
            ['Already mapped before run', $mappedBefore],
            [$dryRun ? 'Would process' : 'Newly processed', $newlyProcessed],
            ['Legacy manual', $counts[LegacyIngredientClassification::LegacyManual->value]],
            ['Verified imported', $counts[LegacyIngredientClassification::VerifiedImported->value]],
            ['Ambiguous barcode', $counts[LegacyIngredientClassification::AmbiguousBarcode->value]],
            ['Duplicate', $counts[LegacyIngredientClassification::Duplicate->value]],
            ['Failed', $failed],
            ['Unprocessed', $unprocessed],
            ['Source changed after mapping', $sourceChanged],
            [$dryRun ? 'Projected mapped after run' : 'Total mapped after run', $totalMappedAfter],
        ]);

        if ($unexpectedFailure || $failed > 0 || $unprocessed > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function mappedCount(int $highWaterMark): int
    {
        return DB::table('ingredients')
            ->join(
                'legacy_ingredient_catalogue_mappings',
                'legacy_ingredient_catalogue_mappings.ingredient_id',
                '=',
                'ingredients.id',
            )
            ->where('ingredients.id', '<=', $highWaterMark)
            ->count();
    }

    private function eligibleQuery(int $highWaterMark): Builder
    {
        $duplicates = DB::table('ingredients')
            ->select('barcode')
            ->where('id', '<=', $highWaterMark)
            ->whereNotNull('barcode')
            ->whereRaw("TRIM(barcode) <> ''")
            ->whereRaw('barcode = TRIM(barcode)')
            ->groupBy('barcode')
            ->havingRaw('COUNT(*) > 1');

        return DB::table('ingredients')
            ->leftJoinSub($duplicates, 'duplicate_barcodes', function ($join): void {
                $join->on('duplicate_barcodes.barcode', '=', 'ingredients.barcode');
            })
            ->leftJoin(
                'legacy_ingredient_catalogue_mappings',
                'legacy_ingredient_catalogue_mappings.ingredient_id',
                '=',
                'ingredients.id',
            )
            ->where('ingredients.id', '<=', $highWaterMark)
            ->select([
                'ingredients.*',
                'legacy_ingredient_catalogue_mappings.id as mapping_id',
                'legacy_ingredient_catalogue_mappings.legacy_checksum as mapping_checksum',
            ])
            ->selectRaw('CASE WHEN duplicate_barcodes.barcode IS NULL THEN 0 ELSE 1 END as has_duplicate_barcode')
            ->orderBy('ingredients.id');
    }
}
