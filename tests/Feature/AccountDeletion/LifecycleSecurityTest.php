<?php

namespace Tests\Feature\AccountDeletion;

use App\Domain\AccountDeletion\AccountLifecycle;
use App\Jobs\ProcessAccountDeletion;
use App\Models\MealPlan;
use App\Models\MealPlanBookmark;
use App\Models\User;
use App\Security\SecondFactor\RecentAuthentication;
use App\Security\SecondFactor\SecondFactorEnrollmentService;
use App\Security\SecondFactor\TotpEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\UsesErasureJournal;
use Tests\TestCase;

class LifecycleSecurityTest extends TestCase
{
    use RefreshDatabase, UsesErasureJournal;

    public function test_an_enrolled_factor_is_required_for_recovery_and_its_code_is_never_flashed(): void
    {
        [$user, $secret] = $this->enrolled();
        app(AccountLifecycle::class)->request($user);
        $data = ['email' => $user->email, 'password' => 'password', 'operation' => 'recover', 'confirmed' => '1'];
        $this->post(route('account-recovery.store'), $data + ['code' => 'bad-code'])
            ->assertSessionHasErrors('password')->assertSessionMissing('_old_input.code');
        $this->assertFalse($user->isActive());
        $this->post(route('account-recovery.store'), $data + ['code' => $this->code($secret)])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_admin_suppression_requires_both_credentials_and_cannot_restore_or_transfer(): void
    {
        [$admin, $secret] = $this->enrolled(true);
        $plan = MealPlan::factory()->retainedUnlisted()->create();
        $bookmark = MealPlanBookmark::factory()->for($plan)->create();
        $this->actingAs($admin)->post(route('admin.retained-plans.moderate', $plan), [
            'action' => 'suppress', 'reason' => 'privacy',
        ])->assertSessionHasErrors(['password', 'code']);
        $this->post(route('admin.retained-plans.moderate', $plan), [
            'action' => 'restore', 'reason' => 'privacy', 'password' => 'password', 'code' => $this->code($secret),
        ])->assertSessionHasErrors('action');
        $this->assertDatabaseCount('retained_plan_removals', 0);
        $this->post(route('admin.retained-plans.moderate', $plan), [
            'action' => 'suppress', 'reason' => 'privacy', 'password' => 'password', 'code' => $this->code($secret),
        ])->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('retained_plan_removals', ['resource_generation' => $plan->erasure_generation, 'state' => 'suppressed']);
        $this->assertNull($plan->fresh()->user_id);
        $this->get(route('meal-plans.show', $plan))->assertNotFound();
        $this->actingAs(User::query()->findOrFail($bookmark->user_id))->post(route('meal-plans.copy', $plan))->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['action' => 'plan.retained_moderated', 'retention_class' => 'moderation_decision_12_months']);
    }

    public function test_ordinary_users_cannot_moderate_retained_plans(): void
    {
        $plan = MealPlan::factory()->retainedUnlisted()->create();
        $this->actingAs(User::factory()->create())->post(route('admin.retained-plans.moderate', $plan), [
            'action' => 'remove', 'reason' => 'privacy', 'password' => 'password', 'code' => '123456',
        ])->assertForbidden();
        $this->assertDatabaseHas('meal_plans', ['id' => $plan->id]);
    }

    public function test_confirmed_under13_promotion_hides_already_retained_plans_without_recovery(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $plan = MealPlan::factory()->public()->for($user, 'owner')->create();
        MealPlanBookmark::factory()->for($plan)->create();
        app(AccountLifecycle::class)->request($user);
        $this->assertNull($plan->fresh()->user_id);
        config(['erasure.privacy_operator' => 'operator:privacy-test', 'erasure.operator_environment' => 'testing']);
        $this->artisan('accounts:purge-under13', ['generation' => $user->account_generation])->assertFailed();
        $this->artisan('accounts:purge-under13', ['generation' => $user->account_generation, '--confirmed' => true])->assertSuccessful();
        $this->get(route('meal-plans.show', $plan))->assertNotFound();
        $this->assertSame('under13', $user->accountDeletion()->sole()->reason);
        Queue::assertPushed(ProcessAccountDeletion::class, 1);
    }

    /** @return array{User, string} */
    private function enrolled(bool $admin = false): array
    {
        $this->freezeSecond();
        config(['administrator-security.verification.delay_seconds' => [0, 0, 0, 0, 0]]);
        $user = User::factory()->create();
        $service = app(SecondFactorEnrollmentService::class);
        $presentation = $service->begin($user, 'password', app(RecentAuthentication::class), app('session.store'));
        $service->confirm($user, $this->code($presentation->manualKey), '192.0.2.5');
        $service->acknowledgeRecoveryCodes($user);
        if ($admin) {
            $user->forceFill(['is_administrator' => true])->save();
        }
        $this->travel(30)->seconds();

        return [$user, $presentation->manualKey];
    }

    private function code(string $secret): string
    {
        return app(TotpEngine::class)->codeAt($secret, app(TotpEngine::class)->currentTimestep());
    }
}
