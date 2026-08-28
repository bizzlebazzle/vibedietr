<?php

namespace App\Jobs;

use App\Domain\RecipeImports\Parsing\NoCredibleRecipeStructure;
use App\Domain\RecipeImports\RecipeImportInputCleaner;
use App\Domain\RecipeImports\RecipeImportStatus;
use App\Domain\RecipeImports\UploadedRecipeImportProcessor;
use App\Models\RecipeImport;
use App\Observability\CorrelationContext;
use App\Queue\CorrelationId;
use App\Queue\Exceptions\JobOperationException;
use App\Queue\Exceptions\NonRetryableJobException;
use App\Queue\Exceptions\RetryableJobException;
use App\Queue\JobFailureReporter;
use App\Queue\QueueName;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class ProcessUploadedRecipeImport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const OPERATION_TYPE = 'recipe_upload_import.process';

    public int $tries = 3;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 86_400;

    public readonly string $importId;

    public readonly string $correlationId;

    public function __construct(string $importId, ?string $correlationId = null)
    {
        if (! Str::isUlid($importId)) {
            throw new InvalidArgumentException('A valid recipe import identifier is required.');
        }
        $this->importId = $importId;
        $this->correlationId = CorrelationId::resolve($correlationId);
        $this->onQueue(QueueName::DEFAULT);
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [10, 60];
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->idempotencyFingerprint()))->releaseAfter(10)->expireAfter(75),
            (new WithoutOverlapping('recipe_upload_import.global'))->releaseAfter(10)->expireAfter(75),
        ];
    }

    public function uniqueId(): string
    {
        return $this->idempotencyFingerprint();
    }

    public function idempotencyFingerprint(): string
    {
        return hash('sha256', self::OPERATION_TYPE.'|'.$this->importId);
    }

    public function handle(UploadedRecipeImportProcessor $processor, RecipeImportInputCleaner $cleaner): void
    {
        app(CorrelationContext::class)->set($this->correlationId);
        try {
            $processor->process($this->importId, $this->attempts() >= $this->tries);
        } catch (NoCredibleRecipeStructure) {
            $exception = new NonRetryableJobException('recipe_structure_not_found');
            $this->markFailed($exception);
            $cleaner->cleanup($this->importId);
            $this->fail($exception);
        } catch (NonRetryableJobException $exception) {
            $this->markFailed($exception);
            $cleaner->cleanup($this->importId);
            $this->fail($exception);
        } catch (RetryableJobException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw RetryableJobException::fromUnexpected($exception, 'recipe_upload_import_unexpected');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $exception ??= new NonRetryableJobException('recipe_upload_import_failed');
        $this->markFailed($exception);
        RecipeImport::query()->whereKey($this->importId)->update(['processing_lease_until' => null]);
        app(RecipeImportInputCleaner::class)->cleanup($this->importId);
        app(JobFailureReporter::class)->report(
            self::class, self::OPERATION_TYPE, $this->job?->uuid(),
            $this->idempotencyFingerprint(), $this->correlationId,
            $this->queue ?? QueueName::DEFAULT, $this->attempts(), $exception, $this->importId,
        );
    }

    private function markFailed(Throwable $exception): void
    {
        $category = $exception instanceof JobOperationException ? $exception->failureCategory : 'unexpected';
        $code = $exception instanceof JobOperationException ? $exception->safeErrorCode : 'unexpected_exception';
        RecipeImport::query()->whereKey($this->importId)
            ->where('status', '!=', RecipeImportStatus::ReviewReady->value)
            ->update([
                'status' => RecipeImportStatus::Failed->value,
                'failure_category' => $category,
                'failure_code' => $code,
                'processing_lease_until' => null,
                'failed_at' => now()->utc(),
                'updated_at' => now()->utc(),
            ]);
    }
}
