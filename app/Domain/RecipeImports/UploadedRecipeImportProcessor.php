<?php

namespace App\Domain\RecipeImports;

use App\Domain\RecipeImports\Extraction\DocumentTextExtractor;
use App\Domain\RecipeImports\Images\CanonicalImage;
use App\Domain\RecipeImports\Images\ImageCanonicalizer;
use App\Domain\RecipeImports\Ocr\ManagedOcrExtractor;
use App\Domain\RecipeImports\Ocr\OcrExtractor;
use App\Domain\RecipeImports\Ocr\OcrQualityApplicator;
use App\Domain\RecipeImports\Ocr\OcrResult;
use App\Domain\RecipeImports\Parsing\RecipeTextParser;
use App\Models\Recipe;
use App\Models\RecipeImport;
use App\Observability\OperationalTelemetry;
use App\Queue\Exceptions\NonRetryableJobException;
use App\Queue\Exceptions\RetryableJobException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;

final class UploadedRecipeImportProcessor
{
    public function __construct(
        private readonly DocumentTextExtractor $documents,
        private readonly ImageCanonicalizer $images,
        private readonly OcrExtractor $ocr,
        private readonly ManagedOcrExtractor $managedOcr,
        private readonly RecipeTextParser $parser,
        private readonly OcrQualityApplicator $quality,
        private readonly RecipeImportMaterializer $materializer,
        private readonly RecipeImportInputCleaner $cleaner,
        private readonly OperationalTelemetry $telemetry,
    ) {}

    public function process(string $importId, bool $allowTechnicalFallback = false): ?Recipe
    {
        $import = DB::transaction(function () use ($importId): ?RecipeImport {
            $import = RecipeImport::query()->lockForUpdate()->find($importId);
            if ($import === null || $import->status->terminal()) {
                return $import;
            }
            if (! in_array($import->type, [RecipeImportType::UploadedText, RecipeImportType::UploadedImage], true)
                || $import->source_disk === null || $import->source_key === null || $import->source_extension === null) {
                throw new LogicException('The uploaded import source is invalid.');
            }
            $import->forceFill([
                'status' => RecipeImportStatus::Validating,
                'started_at' => $import->started_at ?? now()->utc(),
                'processing_lease_until' => now()->utc()->addSeconds(75),
                'failed_at' => null,
            ])->save();

            return $import;
        });
        if ($import === null) {
            return null;
        }
        if ($import->status->terminal()) {
            return $import->recipe_id === null ? null : Recipe::query()->find($import->recipe_id);
        }

        $storage = Storage::disk($import->source_disk);
        if (! $storage->exists($import->source_key)) {
            throw new RetryableJobException('transient_source_unavailable');
        }
        $actualBytes = $storage->size($import->source_key);
        $maximum = $import->type === RecipeImportType::UploadedText ? 2_097_152 : 20_971_520;
        if ($actualBytes < 1 || $actualBytes > $maximum || $actualBytes !== $import->source_bytes) {
            throw new NonRetryableJobException('stored_source_size_invalid');
        }
        $bytes = $storage->get($import->source_key);

        if ($import->type === RecipeImportType::UploadedText) {
            return $this->processDocument($import, $bytes);
        }

        return $this->processImage($import, $bytes, $allowTechnicalFallback);
    }

    private function processDocument(RecipeImport $import, string $bytes): Recipe
    {
        $started = microtime(true);
        $text = $this->documents->extract($bytes, $import->source_extension);
        $this->telemetry->timing('recipe_import.document_extraction', (microtime(true) - $started) * 1000, [
            'provider' => 'local_document', 'operation' => 'document.extract',
        ]);
        $this->persistExtraction($import->id, $text, DocumentTextExtractor::IDENTIFIER, 'local', []);
        $recipe = $this->materializer->materialize($import->id, $this->parser->parse($text));
        $this->finishAndClean($import->id);

        return $recipe;
    }

    private function processImage(RecipeImport $import, string $bytes, bool $allowTechnicalFallback): Recipe
    {
        $canonical = $this->canonical($import, $bytes);
        $started = microtime(true);
        try {
            $result = $this->ocr->extract($canonical->bytes, $import->correlation_id);
        } catch (RetryableJobException $exception) {
            if (! $allowTechnicalFallback || ! $this->managedOcr->enabled()) {
                throw $exception;
            }
            $result = $this->managedOcr->extract($canonical->bytes, $import->correlation_id);
        }
        $this->telemetry->timing('recipe_import.ocr', (microtime(true) - $started) * 1000, [
            'provider' => $result->provider, 'operation' => 'ocr.extract',
        ]);
        if (! $result->usable()) {
            if ($this->managedOcr->enabled() && $result->provider !== 'google_document_ai') {
                $result = $this->managedOcr->extract($canonical->bytes, $import->correlation_id);
            }
            if (! $result->usable()) {
                throw new NonRetryableJobException('no_usable_ocr_text');
            }
        }

        $this->persistOcr($import->id, $result, $canonical);
        $parsed = $this->quality->apply($this->parser->parse($result->text), $result);
        $recipe = $this->materializer->materialize($import->id, $parsed);
        $this->telemetry->counter('recipe_import.quality', [
            'outcome' => $parsed->completionClassification,
            'provider' => $result->provider,
        ]);
        $this->finishAndClean($import->id);

        return $recipe;
    }

    private function canonical(RecipeImport $import, string $source): CanonicalImage
    {
        if ($import->canonical_disk !== null && $import->canonical_key !== null
            && Storage::disk($import->canonical_disk)->exists($import->canonical_key)) {
            return new CanonicalImage(
                Storage::disk($import->canonical_disk)->get($import->canonical_key),
                (string) $import->canonical_mime,
                (int) $import->image_width,
                (int) $import->image_height,
                (string) (config('production.ocr.preprocessing_version') ?: 'rec17-v1'),
            );
        }
        $started = microtime(true);
        $canonical = $this->images->canonicalize($source, $import->source_extension, (string) $import->source_mime);
        $disk = $import->source_disk;
        $key = 'canonical/'.strtolower((string) Str::ulid());
        if (! Storage::disk($disk)->put($key, $canonical->bytes, ['visibility' => 'private'])) {
            throw new RetryableJobException('canonical_storage_failed');
        }
        DB::transaction(function () use ($import, $disk, $key, $canonical): void {
            RecipeImport::query()->lockForUpdate()->findOrFail($import->id)->forceFill([
                'canonical_disk' => $disk,
                'canonical_key' => $key,
                'canonical_mime' => $canonical->mime,
                'image_width' => $canonical->width,
                'image_height' => $canonical->height,
                'status' => RecipeImportStatus::Extracting,
            ])->save();
        });
        $this->telemetry->timing('recipe_import.canonicalization', (microtime(true) - $started) * 1000, [
            'provider' => 'imagick', 'operation' => 'image.canonicalize',
        ]);

        return $canonical;
    }

    /** @param list<string> $warnings */
    private function persistExtraction(string $id, string $text, string $extractor, string $method, array $warnings): void
    {
        DB::transaction(function () use ($id, $text, $extractor, $method, $warnings): void {
            $import = RecipeImport::query()->lockForUpdate()->findOrFail($id);
            $import->forceFill([
                'status' => RecipeImportStatus::Extracting,
                'source_text' => $text,
                'extraction_method' => $method,
                'extractor_identifier' => $extractor,
                'extractor_version' => (string) (config('production.imports.extractor_version') ?: 'rec17-v1'),
                'extracted_at' => now()->utc(),
                'warnings' => $warnings === [] ? null : $warnings,
            ])->save();
        });
    }

    private function persistOcr(string $id, OcrResult $result, CanonicalImage $canonical): void
    {
        $this->persistExtraction($id, $result->text, $result->provider, 'ocr', $result->warnings);
        RecipeImport::query()->whereKey($id)->update(['provenance' => json_encode([
            'channel' => RecipeImportType::UploadedImage->value,
            'extractor' => $result->provider,
            'extractor_version' => $result->providerVersion,
            'language' => $result->language,
            'preprocessing_version' => $canonical->preprocessingVersion,
            'quality_policy_version' => 'dec007-v1',
        ], JSON_THROW_ON_ERROR)]);
    }

    private function finishAndClean(string $id): void
    {
        RecipeImport::query()->whereKey($id)->update(['processing_lease_until' => null]);
        $this->cleaner->cleanup($id);
    }
}
