<?php

namespace Tests\Feature;

use App\Administrator\AdministratorBreakGlassReplacement;
use App\Administrator\AdministratorPromotionLifecycle;
use App\Administrator\AdministratorRevocation;
use App\Administrator\LastAdministratorGuard;
use App\Audit\AuditEventRecorder;
use App\Models\AdministratorLifecycleState;
use App\Models\AdministratorPromotionRequest;
use App\Models\SecondFactor;
use App\Models\SecurityNotificationIntent;
use App\Models\User;
use App\Security\Notifications\SecurityNotificationIntentService;
use App\Security\SecondFactor\RecentAuthentication;
use App\Security\SecondFactor\SecondFactorEnrollmentService;
use App\Security\SecondFactor\SecondFactorVerifier;
use App\Security\SecondFactor\TotpEngine;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\Expectation;
use RuntimeException;
use Tests\TestCase;

class AdministratorLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-08-12 12:00:00 UTC');
        Queue::fake();
        config(['administrator-security.verification.delay_seconds' => [0, 0, 0, 0, 0]]);
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    public function test_first_confirmed_configured_bootstrap_succeeds_once_and_persists_marker_audit_and_intent(): void
    {
        [$target] = $this->confirmedUser();
        $this->configureBootstrap($target);

        $this->artisan('administrator:bootstrap')
            ->expectsConfirmation('Confirm this exact target and environment for one-time administrator activation?', 'yes')
            ->assertSuccessful();

        $state = AdministratorLifecycleState::query()->findOrFail(1);
        $this->assertTrue($target->refresh()->isAdministrator());
        $this->assertNotNull($state->bootstrap_completed_at);
        $this->assertNotNull($state->bootstrap_audit_event_id);
        $this->assertDatabaseHas('security_notification_intents', ['event_type' => 'administrator.bootstrap_completed']);

        $target->forceFill(['is_administrator' => false])->save();
        $this->artisan('administrator:bootstrap')
            ->expectsConfirmation('Confirm this exact target and environment for one-time administrator activation?', 'yes')
            ->assertFailed();
        $this->assertFalse($target->refresh()->isAdministrator());
        $this->assertNotNull($state->fresh()->bootstrap_completed_at);
    }

    public function test_bootstrap_requires_configuration_exact_target_eligibility_and_confirmation(): void
    {
        [$eligible] = $this->confirmedUser();
        $wrong = User::factory()->create();
        $this->configureBootstrap($wrong);
        $this->artisan('administrator:bootstrap')->assertFailed();
        $this->assertFalse($eligible->refresh()->isAdministrator());

        $this->configureBootstrap($eligible);
        $this->artisan('administrator:bootstrap')
            ->expectsConfirmation('Confirm this exact target and environment for one-time administrator activation?', 'no')
            ->assertFailed();
        $this->assertFalse($eligible->refresh()->isAdministrator());
    }

    public function test_audit_or_notification_intent_failure_rolls_back_bootstrap_and_marker(): void
    {
        [$target] = $this->confirmedUser();
        $this->configureBootstrap($target);
        $audit = Mockery::mock(AuditEventRecorder::class);
        $expectation = $audit->shouldReceive('record');
        assert($expectation instanceof Expectation);
        $expectation->andThrow(new RuntimeException('audit unavailable'));
        $this->app->instance(AuditEventRecorder::class, $audit);

        $this->artisan('administrator:bootstrap')
            ->expectsConfirmation('Confirm this exact target and environment for one-time administrator activation?', 'yes')
            ->assertFailed();
        $this->assertFalse($target->refresh()->isAdministrator());
        $this->assertNull(AdministratorLifecycleState::query()->findOrFail(1)->bootstrap_completed_at);

        $this->app->forgetInstance(AuditEventRecorder::class);
        $notifications = Mockery::mock(SecurityNotificationIntentService::class);
        $expectation = $notifications->shouldReceive('create');
        assert($expectation instanceof Expectation);
        $expectation->andThrow(new RuntimeException('intent unavailable'));
        $this->app->instance(SecurityNotificationIntentService::class, $notifications);
        $this->artisan('administrator:bootstrap')
            ->expectsConfirmation('Confirm this exact target and environment for one-time administrator activation?', 'yes')
            ->assertFailed();
        $this->assertFalse($target->refresh()->isAdministrator());
        $this->assertNull(AdministratorLifecycleState::query()->findOrFail(1)->bootstrap_completed_at);
    }

    public function test_promotion_requires_privileged_initiator_and_target_acceptance_within_24_hours(): void
    {
        [$administrator, , $adminSecret] = $this->confirmedUser(true);
        [$target, , $targetSecret] = $this->confirmedUser();
        $adminSession = $this->freshProof($administrator, $adminSecret, 'administrator.promotion.initiate');
        $promotion = app(AdministratorPromotionLifecycle::class)->initiate($administrator, $target, $adminSession);

        $this->assertSame('pending', $promotion->status);
        $this->assertEquals(86400, $promotion->created_at->diffInSeconds($promotion->expires_at));
        $this->assertFalse($target->refresh()->isAdministrator());

        $targetSession = $this->freshProof($target, $targetSecret, 'administrator.promotion.accept', advance: false);
        app(AdministratorPromotionLifecycle::class)->accept($target, $promotion, $targetSession);
        $this->assertTrue($target->refresh()->isAdministrator());
        $this->assertSame('accepted', $promotion->fresh()->status);
        $this->assertSame(1, SecurityNotificationIntent::query()->where('event_type', 'administrator.promotion_accepted')->where('recipient_user_id', $target->id)->count());
    }

    public function test_expired_cancelled_and_declined_promotions_cannot_be_accepted(): void
    {
        [$administrator, , $adminSecret] = $this->confirmedUser(true);
        [$target, , $targetSecret] = $this->confirmedUser();
        $promotion = app(AdministratorPromotionLifecycle::class)->initiate($administrator, $target, $this->freshProof($administrator, $adminSecret, 'administrator.promotion.initiate'));
        Date::setTestNow($promotion->expires_at);
        $this->expectException(RuntimeException::class);
        app(AdministratorPromotionLifecycle::class)->accept($target, $promotion, $this->freshProof($target, $targetSecret, 'administrator.promotion.accept', advance: false));
    }

    public function test_target_can_decline_and_any_strongly_authenticated_administrator_can_cancel(): void
    {
        [$administrator, , $adminSecret] = $this->confirmedUser(true);
        [$target, , $targetSecret] = $this->confirmedUser();
        $lifecycle = app(AdministratorPromotionLifecycle::class);
        $first = $lifecycle->initiate($administrator, $target, $this->freshProof($administrator, $adminSecret, 'administrator.promotion.initiate'));
        $lifecycle->decline($target, $first, $this->freshProof($target, $targetSecret, 'administrator.promotion.decline', advance: false));
        $this->assertSame('declined', $first->fresh()->status);

        Date::setTestNow(now()->addSeconds(30));
        $second = $lifecycle->initiate($administrator, $target, $this->freshProof($administrator, $adminSecret, 'administrator.promotion.initiate', advance: false));
        Date::setTestNow(now()->addSeconds(30));
        $lifecycle->cancel($administrator, $second, $this->freshProof($administrator, $adminSecret, 'administrator.promotion.cancel', advance: false));
        $this->assertSame('cancelled', $second->fresh()->status);
        $this->assertFalse($target->refresh()->isAdministrator());
    }

    public function test_revocation_prevents_self_and_last_admin_removal_and_invalidates_sessions_and_remembered_login(): void
    {
        [$actor, , $actorSecret] = $this->confirmedUser(true);
        [$target] = $this->confirmedUser(true);
        $oldRemember = $target->remember_token;
        DB::table('sessions')->insert(['id' => 'revoked-session', 'user_id' => $target->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => now()->timestamp]);

        app(AdministratorRevocation::class)->revoke($actor, $target, $this->freshProof($actor, $actorSecret, 'administrator.revoke'));
        $this->assertFalse($target->refresh()->isAdministrator());
        $this->assertNotSame($oldRemember, $target->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'revoked-session']);
        $this->assertTrue(Gate::forUser($target)->denies('access-admin'));

        $this->expectException(RuntimeException::class);
        app(AdministratorRevocation::class)->revoke($actor, $actor, app('session.store'));
    }

    public function test_sole_administrator_deletion_guard_is_transactionally_enforced(): void
    {
        [$administrator] = $this->confirmedUser(true);
        $this->expectException(RuntimeException::class);
        DB::transaction(fn () => app(LastAdministratorGuard::class)->assertAccountDeletionAllowed($administrator));
    }

    public function test_break_glass_replaces_configured_compromised_admin_without_resetting_bootstrap_marker(): void
    {
        [$compromised] = $this->confirmedUser(true);
        [$replacement] = $this->confirmedUser();
        AdministratorLifecycleState::query()->findOrFail(1)->forceFill(['bootstrap_completed_at' => now(), 'bootstrap_correlation_id' => 'original-bootstrap'])->save();
        config([
            'administrator-security.lifecycle.break_glass.enabled' => true,
            'administrator-security.lifecycle.break_glass.expected_environment' => 'testing',
            'administrator-security.lifecycle.break_glass.replacement_email' => $replacement->email,
            'administrator-security.lifecycle.break_glass.compromised_email' => $compromised->email,
            'administrator-security.lifecycle.break_glass.operator_reference' => 'operator:emergency-1',
        ]);

        app(AdministratorBreakGlassReplacement::class)->execute();
        $this->assertTrue($replacement->refresh()->isAdministrator());
        $this->assertFalse($compromised->refresh()->isAdministrator());
        $this->assertSame('original-bootstrap', AdministratorLifecycleState::query()->findOrFail(1)->bootstrap_correlation_id);
    }

    public function test_ordinary_user_cannot_initiate_or_revoke_and_cannot_accept_another_targets_request(): void
    {
        [$ordinary, , $ordinarySecret] = $this->confirmedUser();
        [$target] = $this->confirmedUser();
        $this->actingAs($ordinary);
        $this->post(route('administrator.lifecycle.promotions.initiate'), ['target_user_id' => $target->id])->assertSessionHasErrors('lifecycle');

        $promotion = AdministratorPromotionRequest::query()->create([
            'target_user_id' => $target->id,
            'initiated_by_user_id' => null,
            'status' => 'pending',
            'correlation_id' => '01k2wrongtarget000000000000000',
            'expires_at' => now()->addDay(),
        ]);
        $this->actingAs($ordinary);
        $this->freshProof($ordinary, $ordinarySecret, 'administrator.promotion.accept');
        $this->post(route('administrator.lifecycle.promotions.accept', $promotion))->assertSessionHasErrors('lifecycle');
        $this->assertFalse($ordinary->refresh()->isAdministrator());
    }

    private function configureBootstrap(User $target): void
    {
        config([
            'administrator-security.lifecycle.bootstrap.enabled' => true,
            'administrator-security.lifecycle.bootstrap.expected_environment' => 'testing',
            'administrator-security.lifecycle.bootstrap.target_email' => $target->email,
            'administrator-security.lifecycle.bootstrap.operator_reference' => 'operator:deployment-1',
            'administrator-security.lifecycle.bootstrap.operation_version' => 'test-version',
        ]);
    }

    /** @return array{User, SecondFactor, string} */
    private function confirmedUser(bool $administrator = false): array
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'password' => 'correct-password']);
        $enrollment = app(SecondFactorEnrollmentService::class);
        $presentation = $enrollment->begin($user, 'correct-password', app(RecentAuthentication::class), app('session.store'));
        $code = app(TotpEngine::class)->codeAt($presentation->manualKey, app(TotpEngine::class)->currentTimestep());
        $enrollment->confirm($user, $code, '192.0.2.5');
        $factor = $enrollment->acknowledgeRecoveryCodes($user);
        if ($administrator) {
            $user->forceFill(['is_administrator' => true])->save();
        }

        return [$user->fresh(), $factor, $presentation->manualKey];
    }

    private function freshProof(User $user, string $secret, string $operation, bool $advance = true): Session
    {
        if ($advance) {
            Date::setTestNow(now()->addSeconds(30));
        }
        $session = app('session.store');
        app(RecentAuthentication::class)->confirmPrimary($user, 'correct-password', $session);
        $code = app(TotpEngine::class)->codeAt($secret, app(TotpEngine::class)->currentTimestep());
        $this->assertTrue(app(SecondFactorVerifier::class)->verify($user, $code, $operation, '192.0.2.20')->succeeded());
        app(RecentAuthentication::class)->rememberFreshFactor($user, $operation, $session);

        return $session;
    }
}
