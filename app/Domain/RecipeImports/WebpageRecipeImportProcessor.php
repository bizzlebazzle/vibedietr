<?php

namespace App\Domain\RecipeImports;

use App\Domain\RecipeImports\Extraction\WebpageRecipeExtractor;
use App\Integrations\RecipeWebpages\WebpageFetcher;
use App\Models\Recipe;
use App\Models\RecipeImport;
use App\Observability\OperationalTelemetry;
use Illuminate\Support\Facades\DB;
use LogicException;

final class WebpageRecipeImportProcessor
{
    public function __construct(
        private readonly WebpageFetcher $fetcher,
        private readonly WebpageRecipeExtractor $extractor,
        private readonly RecipeImportMaterializer $materializer,
        private readonly OperationalTelemetry $telemetry,
    ) {}

    public function process(string $importId): ?Recipe
    {
        $import = DB::transaction(function () use ($importId): ?RecipeImport {
            $import = RecipeImport::query()->lockForUpdate()->find($importId);
            if ($import === null || $import->status === RecipeImportStatus::ReviewReady) {
                return $import;
            }
            if ($import->type !== RecipeImportType::WebpageUrl || $import->submitted_url === null) {
                throw new LogicException('The webpage import source is invalid.');
            }
            $import->forceFill([
                'status' => RecipeImportStatus::Fetching,
                'started_at' => $import->started_at ?? now()->utc(),
                'failed_at' => null,
            ])->save();

            return $import;
        });

        if ($import === null) {
            return null;
        }
        if ($import->status === RecipeImportStatus::ReviewReady) {
            return $import->recipe_id === null ? null : Recipe::query()->find($import->recipe_id);
        }

        $fetched = $this->fetcher->fetch($import->submitted_url, $import->correlation_id);
        $started = microtime(true);
        $extracted = $this->extractor->extract($fetched->html);
        $this->telemetry->timing('recipe_webpage.extraction', (microtime(true) - $started) * 1000, [
            'provider' => WebpageFetcher::PROVIDER,
            'operation' => 'webpage.extract',
            'correlation_id' => $import->correlation_id,
        ]);
        $this->telemetry->counter('recipe_webpage.extraction', [
            'provider' => WebpageFetcher::PROVIDER,
            'outcome' => $extracted->method,
        ]);

        DB::transaction(function () use ($importId, $fetched, $extracted): void {
            $locked = RecipeImport::query()->lockForUpdate()->findOrFail($importId);
            if ($locked->status === RecipeImportStatus::ReviewReady) {
                return;
            }
            $locked->forceFill([
                'status' => RecipeImportStatus::Extracting,
                'source_text' => $extracted->sourceText,
                'final_url' => $fetched->finalUrl,
                'extraction_method' => $extracted->method,
                'extractor_identifier' => $extracted->extractorIdentifier,
                'extractor_version' => $extracted->extractorVersion,
                'extracted_at' => now()->utc(),
                'warnings' => $extracted->recipe->warnings,
                'provenance' => [
                    'channel' => RecipeImportType::WebpageUrl->value,
                    'submitted_url' => $locked->submitted_url,
                    'final_url' => $fetched->finalUrl,
                    'extraction_method' => $extracted->method,
                    'extractor' => $extracted->extractorIdentifier,
                    'extractor_version' => $extracted->extractorVersion,
                    'extracted_at' => now()->utc()->toIso8601String(),
                    'redirect_count' => $fetched->redirectCount,
                    'correlation_id' => $locked->correlation_id,
                ],
            ])->save();
        });

        return $this->materializer->materialize($importId, $extracted->recipe);
    }
}
