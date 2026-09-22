<?php

namespace App\Domain\MealPlans;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditSubjectType;
use App\Models\MealPlan;
use App\Models\MealPlanShare;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MealPlanSharingManager
{
    public function __construct(
        private readonly MealPlanPublicSafety $safety,
        private readonly AuditEventRecorder $audit,
    ) {}

    public function shareWithEmail(MealPlan $mealPlan, string $email, bool $acknowledged, User $actor): MealPlanShare
    {
        return DB::transaction(function () use ($mealPlan, $email, $acknowledged, $actor): MealPlanShare {
            $mealPlan = MealPlan::query()->lockForUpdate()->findOrFail($mealPlan->getKey());
            Gate::forUser($actor)->authorize('update', $mealPlan);

            if ($mealPlan->visibility !== MealPlanVisibility::Private) {
                throw ValidationException::withMessages([
                    'recipient_email' => 'Selected-user sharing is only needed while a plan is private.',
                ]);
            }

            $recipient = User::query()
                ->whereRaw('LOWER(email) = ?', [Str::lower(trim($email))])
                ->first();

            if (! $recipient instanceof User || $recipient->is($actor)) {
                throw ValidationException::withMessages([
                    'recipient_email' => 'No eligible registered user could be selected.',
                ]);
            }

            $privateSnapshotAccess = $this->safety->hasPrivateRecipeSnapshots($mealPlan);
            if ($privateSnapshotAccess && ! $acknowledged) {
                throw ValidationException::withMessages([
                    'acknowledge_private_recipe_snapshots' => 'You must acknowledge that this share grants scoped access to private pinned recipe snapshots.',
                ]);
            }

            if ($mealPlan->shares()->where('recipient_user_id', $recipient->getKey())->exists()) {
                throw ValidationException::withMessages([
                    'recipient_email' => 'This plan is already shared with that selected user.',
                ]);
            }

            $share = new MealPlanShare;
            $share->forceFill([
                'recipient_user_id' => $recipient->getKey(),
                'private_recipe_snapshots_acknowledged_at' => $acknowledged ? now()->utc() : null,
            ]);
            $share->mealPlan()->associate($mealPlan);
            $share->save();

            $this->record($mealPlan, $actor, 'selected_granted', [
                'private_snapshot_access' => $privateSnapshotAccess,
            ]);

            return $share;
        }, 3);
    }

    public function revoke(MealPlan $mealPlan, MealPlanShare $share, User $actor): void
    {
        DB::transaction(function () use ($mealPlan, $share, $actor): void {
            $mealPlan = MealPlan::query()->lockForUpdate()->findOrFail($mealPlan->getKey());
            Gate::forUser($actor)->authorize('update', $mealPlan);
            $share = $mealPlan->shares()->lockForUpdate()->findOrFail($share->getKey());
            $hadPrivateSnapshotAccess = $share->private_recipe_snapshots_acknowledged_at !== null;
            $share->delete();

            $this->record($mealPlan, $actor, 'selected_revoked', [
                'private_snapshot_access' => $hadPrivateSnapshotAccess,
            ]);
        }, 3);
    }

    public function publish(MealPlan $mealPlan, User $actor): MealPlan
    {
        return DB::transaction(function () use ($mealPlan, $actor): MealPlan {
            $mealPlan = MealPlan::query()->lockForUpdate()->findOrFail($mealPlan->getKey());
            Gate::forUser($actor)->authorize('update', $mealPlan);
            $this->safety->assertPublicSafe($mealPlan);

            $mealPlan->forceFill([
                'visibility' => MealPlanVisibility::Public,
                'published_at' => now()->utc(),
            ])->save();

            $this->record($mealPlan, $actor, 'public_published', ['safety_result' => 'safe']);

            return $mealPlan;
        }, 3);
    }

    public function unpublish(MealPlan $mealPlan, User $actor): MealPlan
    {
        return DB::transaction(function () use ($mealPlan, $actor): MealPlan {
            $mealPlan = MealPlan::query()->lockForUpdate()->findOrFail($mealPlan->getKey());
            Gate::forUser($actor)->authorize('update', $mealPlan);

            if ($mealPlan->visibility !== MealPlanVisibility::Public) {
                throw ValidationException::withMessages(['visibility' => 'Only an active public plan can be made private.']);
            }

            $mealPlan->forceFill([
                'visibility' => MealPlanVisibility::Private,
                'published_at' => null,
            ])->save();

            $this->record($mealPlan, $actor, 'public_unpublished');

            return $mealPlan;
        }, 3);
    }

    /** @param array<string, mixed> $extra */
    private function record(MealPlan $mealPlan, User $actor, string $operation, array $extra = []): void
    {
        $this->audit->record(
            AuditAction::PlanSharingChanged,
            AuditActor::authenticatedUser($actor),
            AuditSubject::resource(AuditSubjectType::MealPlan, $mealPlan->getKey()),
            ['operation' => $operation, 'outcome' => 'completed', ...$extra],
            'plan-sharing:'.Str::ulid(),
        );
    }
}
