<?php

namespace App\Domain\MealPlans;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditSubjectType;
use App\Models\MealPlan;
use App\Models\MealPlanBookmark;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MealPlanBookmarkManager
{
    public function __construct(private readonly AuditEventRecorder $audit) {}

    public function add(MealPlan $mealPlan, User $actor): MealPlanBookmark
    {
        return DB::transaction(function () use ($mealPlan, $actor): MealPlanBookmark {
            $mealPlan = MealPlan::query()->lockForUpdate()->findOrFail($mealPlan->getKey());

            if (! $mealPlan->isBookmarkableBy($actor)) {
                throw ValidationException::withMessages([
                    'bookmark' => 'Only another user’s active-owner public plan can be bookmarked.',
                ]);
            }

            if (MealPlanBookmark::query()
                ->where('user_id', $actor->getKey())
                ->where('meal_plan_id', $mealPlan->getKey())
                ->exists()) {
                throw ValidationException::withMessages(['bookmark' => 'You have already bookmarked this plan.']);
            }

            $bookmark = new MealPlanBookmark;
            $bookmark->forceFill(['user_id' => $actor->getKey()]);
            $bookmark->mealPlan()->associate($mealPlan);
            $bookmark->save();

            $this->record($mealPlan, $actor, 'added');

            return $bookmark;
        }, 3);
    }

    public function remove(MealPlanBookmark $bookmark, User $actor): void
    {
        DB::transaction(function () use ($bookmark, $actor): void {
            $bookmark = MealPlanBookmark::query()
                ->where('user_id', $actor->getKey())
                ->lockForUpdate()
                ->findOrFail($bookmark->getKey());
            $mealPlan = MealPlan::query()->lockForUpdate()->findOrFail($bookmark->meal_plan_id);
            $bookmark->delete();

            $removeRetainedPlan = $mealPlan->visibility === MealPlanVisibility::RetainedUnlisted
                && ! $mealPlan->bookmarks()->exists();

            $this->record($mealPlan, $actor, 'removed', $removeRetainedPlan);

            if ($removeRetainedPlan) {
                $mealPlan->delete();
            }
        }, 3);
    }

    private function record(MealPlan $mealPlan, User $actor, string $operation, bool $retainedPlanRemoved = false): void
    {
        $payload = ['operation' => $operation, 'outcome' => 'completed'];
        if ($operation === 'removed') {
            $payload['retained_plan_removed'] = $retainedPlanRemoved;
        }

        $this->audit->record(
            AuditAction::PlanBookmarkChanged,
            AuditActor::authenticatedUser($actor),
            AuditSubject::resource(AuditSubjectType::MealPlan, $mealPlan->getKey()),
            $payload,
            'plan-bookmark:'.Str::ulid(),
        );
    }
}
