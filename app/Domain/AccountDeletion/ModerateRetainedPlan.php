<?php

namespace App\Domain\AccountDeletion;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditSubjectType;
use App\Domain\MealPlans\MealPlanVisibility;
use App\Models\MealPlan;
use App\Models\User;
use App\Security\SecondFactor\PrivilegedWorkflowGuard;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\Ulid;

final class ModerateRetainedPlan
{
    public function apply(User $actor, int $planId, string $action, string $reason, Session $session): void
    {
        abort_unless(in_array($reason, ['privacy', 'security', 'legal', 'moderation'], true) && in_array($action, ['suppress', 'remove'], true)
            && app(PrivilegedWorkflowGuard::class)->allows($actor, 'retained-plan.'.$action, $session), 403);
        DB::transaction(function () use ($actor, $planId, $action, $reason): void {
            $plan = MealPlan::query()->lockForUpdate()->findOrFail($planId);
            abort_unless($plan->visibility === MealPlanVisibility::RetainedUnlisted && $plan->user_id === null, 404);
            $operation = Ulid::fromBinary(substr(hash('sha256', 'plan-'.$action.':'.$plan->erasure_generation, true), 0, 16))->toBase32();
            if ($action === 'remove') {
                app(RetainedPlanRemoval::class)->remove($plan);
            } else {
                $intent = app(ProtectedErasureJournal::class)->appendOnce($operation, [
                    'account_generation' => 'resource_'.$plan->erasure_generation,
                    'installation' => config('erasure.installation'), 'correlation_id' => $operation,
                    'deadline' => now()->utc()->format('Y-m-d\\TH:i:s\\Z'),
                    'state' => 'plan_suppressed', 'policy' => 'DEC-012/DEC-013/DEC-014/DEC-021/DEC-022',
                    'resources' => ['plan_'.$plan->erasure_generation],
                ]);
                DB::table('retained_plan_removals')->updateOrInsert(
                    ['resource_generation' => $plan->erasure_generation],
                    ['meal_plan_id' => $plan->getKey(), 'state' => 'suppressed',
                        'recorded_at' => CarbonImmutable::parse($intent['deadline'])],
                );
            }
            app(AuditEventRecorder::class)->record(AuditAction::RetainedPlanModerated,
                AuditActor::administrator($actor), AuditSubject::resource(AuditSubjectType::MealPlan, $planId),
                ['event' => $action === 'remove' ? 'plan_removed' : 'plan_suppressed', 'outcome' => 'completed', 'reason' => $reason], $operation);
        }, 3);
    }
}
