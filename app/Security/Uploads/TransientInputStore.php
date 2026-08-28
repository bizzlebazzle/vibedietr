<?php

namespace App\Security\Uploads;

use App\Observability\OperationalTelemetry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class TransientInputStore
{
    public function __construct(
        private readonly ContentTypeInspector $inspector,
        private readonly OperationalTelemetry $telemetry,
    ) {}

    /** @param list<string> $allowedMimes */
    public function store(UploadedFile $file, ?int $maxBytes = null, array $allowedMimes = []): TransientInputHandle
    {
        $maxBytes ??= (int) config('security.uploads.max_bytes');
        $bytes = $file->getSize();
        if (! is_int($bytes) || $bytes < 0 || $bytes > $maxBytes) {
            throw UploadValidationException::oversized();
        }

        $inspection = $this->inspector->inspect(
            $file->getRealPath(),
            $file->getClientMimeType(),
            $file->getClientOriginalExtension(),
        );
        $this->inspector->assertAccepted($inspection, $allowedMimes);

        $disk = (string) config('security.uploads.transient_disk');
        $prefix = trim((string) config('security.uploads.prefix'), '/');
        $key = $prefix.'/'.strtolower((string) Str::ulid());

        try {
            $stream = fopen($file->getRealPath(), 'rb');
            if ($stream === false || ! Storage::disk($disk)->put($key, $stream, ['visibility' => 'private'])) {
                throw UploadValidationException::storageFailed();
            }
        } catch (UploadValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw UploadValidationException::storageFailed();
        } finally {
            if (isset($stream) && is_resource($stream)) {
                fclose($stream);
            }
        }

        return new TransientInputHandle($disk, $key, $inspection->detectedMime, $bytes);
    }

    public function cleanup(TransientInputHandle $handle): CleanupOutcome
    {
        try {
            $storage = Storage::disk($handle->disk);
            if (! $storage->exists($handle->key)) {
                return CleanupOutcome::Missing;
            }

            return $storage->delete($handle->key) ? CleanupOutcome::Deleted : $this->cleanupFailed();
        } catch (Throwable) {
            return $this->cleanupFailed();
        }
    }

    private function cleanupFailed(): CleanupOutcome
    {
        $this->telemetry->counter('transient_input.cleanup', [
            'outcome' => 'failed',
            'operation' => 'transient_input.cleanup',
        ]);

        return CleanupOutcome::Failed;
    }
}
