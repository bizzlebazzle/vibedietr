<?php

namespace App\Domain\AccountDeletion;

use App\Models\MealPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\Ulid;

final class RetainedPlanRemoval
{
    /** Caller holds the plan lock and transaction. No restore or ownership mutation. */
    public function remove(MealPlan $plan): void
    {
        $generation = $plan->erasure_generation;
        $operation = Ulid::fromBinary(substr(hash('sha256', 'plan-removed:'.$generation, true), 0, 16))->toBase32();
        $intent = app(ProtectedErasureJournal::class)->appendOnce($operation, [
            'account_generation' => 'resource_'.$generation,
            'installation' => config('erasure.installation'), 'correlation_id' => $operation,
            'deadline' => now()->utc()->format('Y-m-d\\TH:i:s\\Z'),
            'state' => 'plan_removed', 'policy' => 'DEC-012/DEC-013/DEC-014/DEC-021',
            'resources' => ['plan_'.$generation],
        ]);
        app(ArchivePlanHistory::class)->preserve($plan);
        DB::table('retained_plan_removals')->updateOrInsert(
            ['resource_generation' => $generation],
            ['meal_plan_id' => $plan->getKey(), 'state' => 'removed', 'recorded_at' => CarbonImmutable::parse($intent['deadline'])],
        );
        DB::table('meal_plans')->where('id', $plan->getKey())->delete();
    }
}
