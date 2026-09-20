<?php

namespace App\Jobs;

use App\Domain\MealPlans\MealPlanRecipeVersionReviewNotifier;
use App\Queue\CorrelationId;
use App\Queue\Exceptions\JobOperationException;
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

final class CreateMealPlanRecipeVersionReviews implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const OPERATION_TYPE = 'meal_plan_recipe_version_reviews.create';

    public int $tries = 3;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 86_400;

    public readonly string $recipeVersionId;

    public readonly string $correlationId;

    public function __construct(string $recipeVersionId, ?string $correlationId = null)
    {
        if (! Str::isUlid($recipeVersionId)) {
            throw new InvalidArgumentException('A valid recipe version identifier is required.');
        }

        $this->recipeVersionId = $recipeVersionId;
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
        return [(new WithoutOverlapping('plan-recipe-version:'.$this->recipeVersionId))->releaseAfter(10)->expireAfter(75)];
    }

    public function uniqueId(): string
    {
        return $this->idempotencyFingerprint();
    }

    public function idempotencyFingerprint(): string
    {
        return hash('sha256', self::OPERATION_TYPE.'|'.$this->recipeVersionId);
    }

    public function handle(MealPlanRecipeVersionReviewNotifier $notifier): void
    {
        try {
            $notifier->createForVersion($this->recipeVersionId, $this->correlationId);
        } catch (Throwable $exception) {
            throw RetryableJobException::fromUnexpected($exception, 'meal_plan_recipe_version_review_unexpected');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $exception ??= new RetryableJobException('meal_plan_recipe_version_review_failed');
        $code = $exception instanceof JobOperationException ? $exception->safeErrorCode : 'unexpected_exception';
        app(JobFailureReporter::class)->report(
            jobClass: self::class,
            operationType: self::OPERATION_TYPE,
            jobIdentifier: $this->job?->uuid(),
            idempotencyFingerprint: $this->idempotencyFingerprint(),
            correlationId: $this->correlationId,
            queue: $this->queue ?? QueueName::DEFAULT,
            attemptCount: $this->attempts(),
            exception: $exception,
            resourceIdentifier: $this->recipeVersionId,
        );
    }
}
