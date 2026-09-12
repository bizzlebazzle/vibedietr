<?php

namespace App\Jobs;

use App\Domain\Nutrition\ProcessRecipeNutritionRecalculation;
use App\Domain\Nutrition\RecipeNutritionRecalculationState;
use App\Models\RecipeNutritionRecalculation;
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

final class RecalculateRecipeNutrition implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const OPERATION_TYPE = 'recipe_nutrition.recalculate';

    public int $tries = 3;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 86_400;

    public readonly string $recalculationId;

    public readonly string $recipeVersionId;

    public readonly string $correlationId;

    public function __construct(string $recalculationId, string $recipeVersionId, ?string $correlationId = null)
    {
        foreach (['recalculation' => $recalculationId, 'recipe version' => $recipeVersionId] as $label => $identifier) {
            if (! Str::isUlid($identifier)) {
                throw new InvalidArgumentException("A valid {$label} identifier is required.");
            }
        }

        $this->recalculationId = $recalculationId;
        $this->recipeVersionId = $recipeVersionId;
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
        return [(new WithoutOverlapping('recipe-nutrition:'.$this->recipeVersionId))->releaseAfter(10)->expireAfter(75)];
    }

    public function uniqueId(): string
    {
        return $this->idempotencyFingerprint();
    }

    public function idempotencyFingerprint(): string
    {
        return hash('sha256', self::OPERATION_TYPE.'|'.$this->recalculationId);
    }

    public function handle(ProcessRecipeNutritionRecalculation $processor): void
    {
        try {
            $processor->process($this->recalculationId);
        } catch (Throwable $exception) {
            throw RetryableJobException::fromUnexpected($exception, 'recipe_nutrition_recalculation_unexpected');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $exception ??= new RetryableJobException('recipe_nutrition_recalculation_failed');
        $code = $exception instanceof JobOperationException ? $exception->safeErrorCode : 'unexpected_exception';

        RecipeNutritionRecalculation::query()
            ->whereKey($this->recalculationId)
            ->whereIn('state', [
                RecipeNutritionRecalculationState::Queued->value,
                RecipeNutritionRecalculationState::Processing->value,
                RecipeNutritionRecalculationState::Failed->value,
            ])
            ->update([
                'state' => RecipeNutritionRecalculationState::Failed->value,
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
            resourceIdentifier: $this->recalculationId,
        );
    }
}
