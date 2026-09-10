<?php

namespace App\Jobs;

use App\Domain\Catalogue\CatalogueProviderRefreshState;
use App\Domain\Catalogue\ProcessCatalogueProviderRefresh;
use App\Models\CatalogueProviderRefresh;
use App\Observability\CorrelationContext;
use App\Queue\CorrelationId;
use App\Queue\Exceptions\JobOperationException;
use App\Queue\Exceptions\RetryableJobException;
use App\Queue\JobFailureReporter;
use App\Queue\QueueName;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class RefreshOpenFoodFactsCatalogueItem implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const OPERATION_TYPE = 'catalogue.openfoodfacts_refresh';

    public int $tries = 3;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 86_400;

    public readonly string $refreshId;

    public readonly string $correlationId;

    public function __construct(string $refreshId, ?string $correlationId = null)
    {
        if (! Str::isUlid($refreshId)) {
            throw new InvalidArgumentException('A valid provider refresh identifier is required.');
        }

        $this->refreshId = $refreshId;
        $this->correlationId = CorrelationId::resolve($correlationId);
        $this->onQueue(QueueName::DEFAULT);
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->idempotencyFingerprint()))->releaseAfter(10)->expireAfter(75)];
    }

    public function uniqueId(): string
    {
        return $this->idempotencyFingerprint();
    }

    public function idempotencyFingerprint(): string
    {
        return hash('sha256', self::OPERATION_TYPE.'|'.$this->refreshId);
    }

    public function handle(ProcessCatalogueProviderRefresh $processor): void
    {
        app(CorrelationContext::class)->set($this->correlationId);

        try {
            $processor->process($this->refreshId);
        } catch (RetryableJobException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw RetryableJobException::fromUnexpected($exception, 'catalogue_refresh_unexpected');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $exception ??= new RetryableJobException('catalogue_refresh_failed');
        $code = $exception instanceof JobOperationException ? $exception->safeErrorCode : 'unexpected_exception';

        CatalogueProviderRefresh::query()
            ->whereKey($this->refreshId)
            ->whereIn('state', [
                CatalogueProviderRefreshState::Queued->value,
                CatalogueProviderRefreshState::Processing->value,
            ])
            ->update([
                'state' => CatalogueProviderRefreshState::Failed->value,
                'active_key' => null,
                'failure_code' => $code,
                'completed_at' => Date::now()->utc(),
                'updated_at' => Date::now()->utc(),
            ]);

        app(JobFailureReporter::class)->report(
            jobClass: self::class,
            operationType: self::OPERATION_TYPE,
            jobIdentifier: $this->job?->uuid(),
            idempotencyFingerprint: $this->idempotencyFingerprint(),
            correlationId: $this->correlationId,
            queue: $this->queue ?? QueueName::DEFAULT,
            attemptCount: $this->attempts(),
            exception: $exception,
            resourceIdentifier: $this->refreshId,
        );
    }
}
