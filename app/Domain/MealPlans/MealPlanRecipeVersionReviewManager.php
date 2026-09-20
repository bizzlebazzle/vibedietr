<?php

namespace App\Domain\MealPlans;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditSubjectType;
use App\Domain\Nutrition\RecipeNutritionSourceSelector;
use App\Models\AuditEvent;
use App\Models\MealPlanRecipeEntry;
use App\Models\MealPlanRecipeVersionReview;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class MealPlanRecipeVersionReviewManager
{
    public function __construct(
        private readonly RecipeNutritionSourceSelector $nutrition,
        private readonly AuditEventRecorder $audit,
    ) {}

    public function update(MealPlanRecipeVersionReview $review, User $actor): MealPlanRecipeEntry
    {
        return DB::transaction(function () use ($review, $actor): MealPlanRecipeEntry {
            [$review, $entry, $version] = $this->lockPending($review, $actor);
            $recipe = $version->recipe()->lockForUpdate()->firstOrFail();
            if (! $recipe->isPubliclyViewable() && (int) $recipe->user_id !== (int) $actor->getKey()) {
                abort(403);
            }

            $effective = $this->nutrition->effective($version);
            DB::table('meal_plan_recipe_entries')->where('id', $entry->getKey())->update([
                'recipe_version_id' => $version->getKey(),
                'recipe_version_number' => $version->version_number,
                'recipe_snapshot' => json_encode($version->snapshot, JSON_THROW_ON_ERROR),
                'nutrition_snapshot' => json_encode([
                    'source' => $effective['source']->value,
                    'values' => $effective['values'],
                    'provenance' => $effective['provenance'],
                    'ingredient_estimate' => $this->nutrition->estimate($version),
                ], JSON_THROW_ON_ERROR),
                'updated_at' => now()->utc(),
            ]);

            $audit = $this->recordDecision($review, $entry, $version, $actor, 'updated');
            $this->resolve($review, MealPlanRecipeVersionReviewStatus::Updated, $audit->getKey());
            MealPlanRecipeVersionReview::query()
                ->where('meal_plan_recipe_entry_id', $entry->getKey())
                ->whereKeyNot($review->getKey())
                ->where('status', MealPlanRecipeVersionReviewStatus::Pending)
                ->whereHas('recipeVersion', fn ($query) => $query->where('version_number', '<=', $version->version_number))
                ->update(['status' => MealPlanRecipeVersionReviewStatus::Inapplicable, 'resolved_at' => now()->utc()]);

            return $entry->fresh();
        }, 3);
    }

    public function retain(MealPlanRecipeVersionReview $review, User $actor): MealPlanRecipeEntry
    {
        return DB::transaction(function () use ($review, $actor): MealPlanRecipeEntry {
            [$review, $entry, $version] = $this->lockPending($review, $actor);
            $audit = $this->recordDecision($review, $entry, $version, $actor, 'retained');
            $this->resolve($review, MealPlanRecipeVersionReviewStatus::Retained, $audit->getKey());

            return $entry;
        }, 3);
    }

    /** @return array{MealPlanRecipeVersionReview, MealPlanRecipeEntry, RecipeVersion} */
    private function lockPending(MealPlanRecipeVersionReview $review, User $actor): array
    {
        $review = MealPlanRecipeVersionReview::query()->lockForUpdate()->findOrFail($review->getKey());
        $entry = MealPlanRecipeEntry::query()->lockForUpdate()->findOrFail($review->meal_plan_recipe_entry_id);
        $entry->load('slot.day.mealPlan');
        Gate::forUser($actor)->authorize('update', $entry->slot->day->mealPlan);
        if ($review->status !== MealPlanRecipeVersionReviewStatus::Pending) {
            throw ValidationException::withMessages(['review' => 'This recipe-version review is already resolved.']);
        }
        if ($entry->consumptionState()->exists()) {
            $this->resolve($review, MealPlanRecipeVersionReviewStatus::Inapplicable);
            throw ValidationException::withMessages(['review' => 'Consumed entries cannot change their pinned recipe version.']);
        }

        $version = RecipeVersion::query()->lockForUpdate()->findOrFail($review->recipe_version_id);
        if ($version->recipe_id !== $entry->recipe_id || $version->version_number <= $entry->recipe_version_number) {
            $this->resolve($review, MealPlanRecipeVersionReviewStatus::Inapplicable);
            throw ValidationException::withMessages(['review' => 'This newer-version review is no longer applicable.']);
        }

        return [$review, $entry, $version];
    }

    private function recordDecision(
        MealPlanRecipeVersionReview $review,
        MealPlanRecipeEntry $entry,
        RecipeVersion $version,
        User $actor,
        string $decision,
    ): AuditEvent {
        return $this->audit->record(
            AuditAction::PlanRecipeVersionReviewed,
            AuditActor::authenticatedUser($actor),
            AuditSubject::resource(AuditSubjectType::PlanSnapshot, 'plan-entry:'.$entry->getKey()),
            ['decision' => $decision, 'current_version_id' => $entry->recipe_version_id, 'offered_version_id' => $version->getKey()],
            $review->correlation_id,
            $review->getKey(),
        );
    }

    private function resolve(MealPlanRecipeVersionReview $review, MealPlanRecipeVersionReviewStatus $status, ?string $auditId = null): void
    {
        $review->forceFill(['status' => $status, 'audit_event_id' => $auditId, 'resolved_at' => now()->utc()])->save();
    }
}
