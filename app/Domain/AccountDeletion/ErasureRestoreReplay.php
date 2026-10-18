<?php

namespace App\Domain\AccountDeletion;

use App\Domain\MealPlans\MealPlanPublicSafety;
use App\Models\AccountDeletion;
use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\User;
use App\Security\Notifications\ProductionSecurityReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Only for an isolated, writer-fenced restore target. Release is a separate verified operation. */
final class ErasureRestoreReplay
{
    public function replay(ErasureCheckpoint $anchor): ErasureCheckpoint
    {
        app(PrivacyOperator::class)->reference();
        $state = app(ErasureJournalState::class);
        $entries = $state->at($anchor);
        foreach (['users' => 'account_generation', 'recipes' => 'erasure_generation', 'meal_plans' => 'erasure_generation'] as $table => $column) {
            if (DB::table($table)->whereNull($column)->exists()) {
                throw new JournalUnavailable;
            }
        }
        foreach (AccountDeletion::query()->cursor() as $request) {
            if (User::query()->whereKey($request->user_id)->value('account_generation') !== $request->account_generation) {
                throw new JournalUnavailable;
            }
        }
        // Resource decisions are durable independently of the account request.
        // Archive using the historical request/removal clocks, even after recovery.
        foreach ($entries as $index => $entry) {
            if (! in_array($entry['state'], ['plan_removed', 'plan_suppressed'], true)) {
                continue;
            }
            foreach ($this->plans($entry) as $plan) {
                DB::transaction(function () use ($plan, $entry, $entries, $index): void {
                    if ($entry['state'] === 'plan_suppressed') {
                        DB::table('retained_plan_removals')->updateOrInsert(
                            ['resource_generation' => $plan->erasure_generation],
                            ['meal_plan_id' => $plan->getKey(), 'state' => 'suppressed',
                                'recorded_at' => CarbonImmutable::parse($entry['deadline'])],
                        );

                        return;
                    }
                    for ($prior = $index - 1; $prior >= 0; $prior--) {
                        $candidate = $entries[$prior];
                        if (! in_array('plan_'.$plan->erasure_generation, $candidate['resources'], true)
                            || ! in_array($candidate['state'], ErasureJournalState::ACCOUNT_STATES, true)) {
                            continue;
                        }
                        $owner = User::query()->where('account_generation', $candidate['account_generation'])->first();
                        if ($owner && $candidate['state'] === 'requested') {
                            app(ArchivePlanHistory::class)->preserveForReplay($plan, $owner, $candidate, CarbonImmutable::parse($entry['deadline']));
                        }
                        break;
                    }
                    app(RetainedPlanRemoval::class)->replay($plan, $entry);
                });
            }
        }
        foreach ($state->latest($entries) as $entry) {
            if ($entry['state'] === 'recovered') {
                $this->recover($entry);

                continue;
            }
            $due = CarbonImmutable::parse($entry['deadline'])->lessThanOrEqualTo(now()->utc());
            if ($entry['state'] !== 'requested' || $due) {
                $prepared = $this->preparePlans($entry, false);
                $this->recipeState($entry);
                app(PurgeAccount::class)->erase($entry, $entry['resources'], receipt: false, retainedPlanGenerations: $prepared['eligible']);

                continue;
            }
            $this->pending($entry);
        }
        // Any concurrent journal write invalidates this replay; do not authorize release.
        $state->at($anchor);

        return $anchor;
    }

    private function pending(array $entry): void
    {
        $owner = User::query()->where('account_generation', $entry['account_generation'])->first();
        if (! $owner) {
            // A future recovery request cannot be reconstructed without its private data.
            throw new JournalUnavailable;
        }
        DB::transaction(function () use ($owner, $entry): void {
            $requestId = app(ErasureJournalState::class)->request($entry);
            $request = AccountDeletion::query()->where('user_id', $owner->getKey())->lockForUpdate()->first();
            if ($request && $request->id !== $requestId) {
                throw new JournalUnavailable;
            }
            $recovery = $request->recovery_state ?? ['plans' => [], 'resources' => $entry['resources']];
            foreach ($this->plans($entry) as $plan) {
                $this->assertOwner($plan, $owner);
                $visibility = $this->visibility($entry, $plan);
                $recovery['plans'][(string) $plan->id] = ['visibility' => $visibility, 'generation' => $plan->erasure_generation];
            }
            $request ??= new AccountDeletion;
            $request->forceFill([
                'id' => $requestId, 'user_id' => $owner->getKey(), 'account_generation' => $entry['account_generation'],
                'state' => 'recoverable', 'reason' => 'ordinary',
                'requested_at' => CarbonImmutable::parse($entry['deadline'])->subDays(30),
                'deadline' => CarbonImmutable::parse($entry['deadline']), 'correlation_id' => strtolower($entry['correlation_id']),
                'operation_id' => $requestId, 'recovery_state' => $recovery,
            ])->save();
            $prepared = $this->preparePlans($entry, true);
            $recovery['snapshots'] = array_replace($recovery['snapshots'] ?? [], $prepared['snapshots']);
            $request->forceFill(['recovery_state' => $recovery])->save();
        });
    }

    private function recover(array $entry): void
    {
        $owner = User::query()->where('account_generation', $entry['account_generation'])->first();
        if (! $owner) {
            throw new JournalUnavailable;
        }
        if ($owner->getRawOriginal('is_administrator')) {
            app(ProductionSecurityReadiness::class)->assertReady();
        }
        DB::transaction(function () use ($owner, $entry): void {
            $request = AccountDeletion::query()->where('user_id', $owner->getKey())->lockForUpdate()->first();
            if ($request && $request->id !== app(ErasureJournalState::class)->request($entry)) {
                throw new JournalUnavailable;
            }
            foreach ($this->plans($entry) as $plan) {
                $this->assertOwner($plan, $owner);
                if ($plan->user_id !== null) {
                    continue;
                }
                if (DB::table('retained_plan_removals')->where('resource_generation', $plan->erasure_generation)->whereIn('state', ['removed', 'under13'])->exists()) {
                    continue;
                }
                // Detached snapshots were minimized in SQL. Restoring their private
                // metadata requires the protected recovery context from that SQL set.
                $snapshots = $request?->recovery_state['snapshots'][$plan->erasure_generation] ?? null;
                if ($snapshots === null) {
                    throw new JournalUnavailable;
                }
                app(PublicPlanSnapshotProtection::class)->restore($plan, $snapshots);
                $plan->forceFill(['user_id' => $owner->id, 'visibility' => $this->visibility($entry, $plan), 'retained_unlisted_at' => null])->save();
            }
            $request?->delete();
        });
    }

    /** @return array{snapshots: array, eligible: list<string>} */
    private function preparePlans(array $entry, bool $recoverable): array
    {
        $snapshots = [];
        $eligible = [];
        $owner = User::query()->where('account_generation', $entry['account_generation'])->first();
        foreach ($this->plans($entry) as $plan) {
            $this->assertOwner($plan, $owner);
            $qualifies = ! in_array('policy_under13_no_recovery', $entry['resources'], true)
                && in_array('plan_qualifies_'.$plan->erasure_generation, $entry['resources'], true)
                && $plan->bookmarks()->exists()
                && app(MealPlanPublicSafety::class)->isPublicSafe($plan, retention: true);
            $expected = 'plan_public_hash_'.$plan->erasure_generation.'_';
            $hashes = array_values(array_filter($entry['resources'], fn ($ref) => str_starts_with($ref, $expected)));
            $matches = $qualifies && count($hashes) === 1
                && hash_equals(substr($hashes[0], strlen($expected)), app(PublicPlanSnapshotProtection::class)->fingerprint($plan));
            if ($qualifies && ! $matches && $recoverable) {
                throw new JournalUnavailable;
            }
            if ($matches) {
                $eligible[] = $plan->erasure_generation;
                if ($plan->user_id !== null) {
                    $snapshots[$plan->erasure_generation] = app(PublicPlanSnapshotProtection::class)->protect($plan);
                }
                $plan->forceFill(['user_id' => null, 'visibility' => 'retained_unlisted',
                    'retained_unlisted_at' => CarbonImmutable::parse($entry['deadline'])->subDays(30)])->save();
            }
        }

        return ['snapshots' => $snapshots, 'eligible' => $eligible];
    }

    private function recipeState(array $entry): void
    {
        foreach (Recipe::query()->whereIn('erasure_generation', $this->references($entry, 'recipe_'))->get() as $recipe) {
            $owner = User::query()->where('account_generation', $entry['account_generation'])->first();
            if ($recipe->user_id !== null && (! $owner || (int) $recipe->user_id !== (int) $owner->id)) {
                throw new JournalUnavailable;
            }
            $prefix = 'recipe_state_'.$recipe->erasure_generation.'_';
            $states = array_values(array_filter($entry['resources'], fn ($ref) => str_starts_with($ref, $prefix)));
            if (count($states) !== 1) {
                throw new JournalUnavailable;
            }
            [$visibility, $lifecycle] = explode('_', substr($states[0], strlen($prefix)), 2);
            $fields = ['visibility' => $visibility, 'lifecycle' => $lifecycle];
            if ($visibility === 'public' && $lifecycle === 'finalized') {
                $prefix = 'recipe_version_'.$recipe->erasure_generation.'_';
                $versions = array_values(array_filter($entry['resources'], fn ($ref) => str_starts_with($ref, $prefix)));
                $id = count($versions) === 1 ? substr($versions[0], strlen($prefix)) : null;
                if (! $id || ! $recipe->versions()->whereKey($id)->exists()) {
                    throw new JournalUnavailable;
                }
                $fields['current_recipe_version_id'] = $id;
            }
            $recipe->forceFill($fields)->save();
        }
    }

    private function plans(array $entry)
    {
        return MealPlan::query()->whereIn('erasure_generation', $this->references($entry, 'plan_'))->get();
    }

    private function references(array $entry, string $prefix): array
    {
        return array_map(fn ($ref) => substr($ref, strlen($prefix)), array_values(array_filter($entry['resources'],
            fn ($ref) => preg_match('/^'.preg_quote($prefix, '/').'[0-9a-hjkmnp-tv-z]{26}$/iD', $ref))));
    }

    private function assertOwner(MealPlan $plan, ?User $owner): void
    {
        if ($plan->user_id !== null && (! $owner || (int) $plan->user_id !== (int) $owner->id)) {
            throw new JournalUnavailable;
        }
    }

    private function visibility(array $entry, MealPlan $plan): string
    {
        foreach (['private', 'public'] as $visibility) {
            if (in_array('visibility_'.$visibility.'_'.$plan->erasure_generation, $entry['resources'], true)) {
                return $visibility;
            }
        }
        throw new JournalUnavailable;
    }
}
