<?php

namespace App\Domain\AccountDeletion;

use App\Administrator\LastAdministratorGuard;
use App\Domain\MealPlans\MealPlanPublicSafety;
use App\Domain\MealPlans\MealPlanVisibility;
use App\Models\AccountDeletion;
use App\Models\AccountExport;
use App\Models\Recipe;
use App\Models\RecipeImport;
use App\Models\User;
use App\Security\Notifications\ProductionSecurityReadiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AccountLifecycle
{
    public function request(User $actor, bool $waive = false, bool $under13 = false): AccountDeletion
    {
        // A prepared record fixes the original clock even if journal replication
        // fails. It also blocks ordinary use until retry/reconciliation succeeds.
        $id = DB::transaction(function () use ($actor, $waive, $under13): string {
            app(LastAdministratorGuard::class)->assertAccountDeletionAllowed($actor);
            $user = User::query()->lockForUpdate()->findOrFail($actor->getKey());
            $existing = AccountDeletion::query()->where('user_id', $user->getKey())->first();
            if ($existing) {
                if ($under13 && $existing->reason !== 'under13') {
                    $existing->forceFill(['reason' => 'under13', 'deadline' => now()->utc(),
                        'state' => 'preparing', 'operation_id' => (string) Str::ulid()])->save();
                }

                return $existing->id;
            }
            $plans = [];
            foreach ($user->mealPlans()->lockForUpdate()->get() as $plan) {
                $plans[(string) $plan->getKey()] = ['visibility' => $plan->visibility->value, 'generation' => $plan->erasure_generation];
            }
            $record = new AccountDeletion;
            $record->forceFill([
                'user_id' => $user->getKey(), 'account_generation' => $user->account_generation,
                'state' => 'preparing', 'reason' => $under13 ? 'under13' : ($waive ? 'waived' : 'ordinary'),
                'requested_at' => now()->utc(), 'deadline' => ($waive || $under13) ? now()->utc() : now()->utc()->addDays(30),
                'correlation_id' => (string) Str::ulid(), 'operation_id' => (string) Str::ulid(),
                'recovery_state' => ['plans' => $plans, 'resources' => $this->resources($user)],
            ])->save();

            return $record->id;
        }, 3);
        $this->apply($id);

        return AccountDeletion::query()->findOrFail($id);
    }

    public function recover(User $actor): void
    {
        DB::transaction(function () use ($actor): void {
            $record = AccountDeletion::query()->where('user_id', $actor->getKey())->lockForUpdate()->firstOrFail();
            if ($record->reason !== 'ordinary' || ! in_array($record->state, ['preparing', 'recoverable', 'recovering'], true)
                || now()->utc()->greaterThanOrEqualTo($record->deadline)) {
                throw ValidationException::withMessages(['recovery' => 'This account can no longer be recovered.']);
            }
            if ($actor->getRawOriginal('is_administrator')) {
                app(ProductionSecurityReadiness::class)->assertReady();
            }
            if ($record->state !== 'recovering') {
                $record->forceFill(['state' => 'recovering', 'operation_id' => (string) Str::ulid()])->save();
            }
        }, 3);
        $this->apply((string) AccountDeletion::query()->where('user_id', $actor->getKey())->value('id'));
    }

    public function waive(User $actor): void
    {
        DB::transaction(function () use ($actor): void {
            $record = AccountDeletion::query()->where('user_id', $actor->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($record->state, ['preparing', 'recoverable', 'waiving'], true)) {
                throw ValidationException::withMessages(['recovery' => 'This request cannot be changed.']);
            }
            if ($record->state !== 'waiving' && $record->reason === 'ordinary') {
                $record->forceFill([
                    'state' => 'waiving', 'reason' => 'waived', 'deadline' => $record->deadline->min(now()->utc()),
                    'operation_id' => (string) Str::ulid(),
                ])->save();
            }
        }, 3);
        $this->apply((string) AccountDeletion::query()->where('user_id', $actor->getKey())->value('id'));
    }

    public function apply(string $id): void
    {
        DB::transaction(function () use ($id): void {
            $record = AccountDeletion::query()->lockForUpdate()->find($id);
            if (! $record || ! in_array($record->state, ['preparing', 'recovering', 'waiving'], true)) {
                return;
            }
            $user = User::query()->lockForUpdate()->findOrFail($record->user_id);
            $state = $record->state === 'recovering' ? 'recovered'
                : ($record->reason === 'ordinary' ? 'requested' : ($record->reason === 'under13' ? 'under13' : 'waived'));
            app(JournalManifest::class)->append($record->operation_id, $this->intent($record, $state));
            if ($state === 'recovered') {
                foreach (($record->recovery_state['plans'] ?? []) as $planId => $prior) {
                    if (DB::table('retained_plan_removals')->where('resource_generation', $prior['generation'])->whereIn('state', ['removed', 'under13'])->exists()) {
                        continue;
                    }
                    DB::table('meal_plans')->where('id', $planId)->where('erasure_generation', $prior['generation'])->whereNull('user_id')->update([
                        'user_id' => $user->getKey(), 'visibility' => $prior['visibility'],
                        'retained_unlisted_at' => null,
                    ]);
                }
                app(LifecycleAudit::class)->record($user, 'recovered', $record->correlation_id);
                $record->delete();
                DB::afterCommit(fn () => app(ResumeAccountWork::class)->dispatch($user));

                return;
            }
            $recovery = $record->recovery_state ?? ['plans' => []];
            if ($record->reason === 'under13') {
                foreach ($recovery['plans'] as $planId => $prior) {
                    DB::table('retained_plan_removals')->updateOrInsert(
                        ['resource_generation' => $prior['generation']],
                        ['meal_plan_id' => $planId, 'state' => 'under13', 'recorded_at' => now()->utc()],
                    );
                }
            }
            foreach ($user->mealPlans()->where('visibility', 'public')->lockForUpdate()->get() as $plan) {
                $qualifies = $record->reason !== 'under13'
                    && $plan->bookmarks()->where('user_id', '!=', $user->getKey())->exists()
                    && app(MealPlanPublicSafety::class)->isPublicSafe($plan);
                if ($qualifies) {
                    $recovery['plans'][(string) $plan->getKey()] = ['visibility' => 'public', 'generation' => $plan->erasure_generation];
                    $plan->forceFill([
                        'user_id' => null, 'visibility' => MealPlanVisibility::RetainedUnlisted,
                        'retained_unlisted_at' => $record->requested_at,
                    ])->save();
                }
            }
            $record->forceFill(['state' => 'recoverable', 'recovery_state' => $recovery])->save();
            DB::table('sessions')->where('user_id', $user->getKey())->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->forceFill(['remember_token' => null])->save();
            app(LifecycleAudit::class)->record($user, $state, $record->correlation_id);
        }, 3);
    }

    /** @return list<string> */
    private function resources(User $user): array
    {
        $resources = [];
        foreach ($user->mealPlans()->get() as $plan) {
            $resources[] = 'plan_'.$plan->erasure_generation;
        }
        foreach (Recipe::query()->where('user_id', $user->getKey())->get() as $recipe) {
            $resources[] = 'recipe_'.$recipe->erasure_generation;
        }
        foreach (RecipeImport::query()->where('user_id', $user->getKey())->get() as $import) {
            foreach (['source', 'canonical'] as $kind) {
                if ($import->{$kind.'_key'} !== null) {
                    $resources[] = 'object_'.hash('sha256', $import->{$kind.'_disk'}.'|'.$import->{$kind.'_key'});
                }
            }
        }
        foreach (AccountExport::query()->where('user_id', $user->getKey())->get() as $export) {
            $resources[] = 'object_'.hash('sha256', $export->disk.'|'.$export->storageKey());
        }

        return $resources;
    }

    /** @return array<string, mixed> */
    public function intent(AccountDeletion $record, string $state): array
    {
        $references = ['request_'.$record->id, ...($record->recovery_state['resources'] ?? [])];
        if ($record->reason === 'under13') {
            $references[] = 'policy_under13_no_recovery';
        }

        return [
            'account_generation' => $record->account_generation,
            'installation' => config('erasure.installation'),
            'correlation_id' => $record->correlation_id,
            'deadline' => $record->deadline->utc()->format('Y-m-d\\TH:i:s\\Z'),
            'state' => $state, 'policy' => 'DEC-012/DEC-013/DEC-014/DEC-021',
            'resources' => $references,
        ];
    }
}
