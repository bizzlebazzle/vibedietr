<?php

namespace Tests\Feature;

use App\Administrator\AdministratorPromotionLifecycle;
use App\Administrator\AdministratorRevocation;
use App\Administrator\LastAdministratorGuard;
use App\Audit\AuditEventRecorder;
use App\Models\AuditEvent;
use App\Models\SecondFactor;
use App\Models\User;
use App\Security\Notifications\SecurityNotificationIntentService;
use App\Security\SecondFactor\RecentAuthentication;
use App\Security\SecondFactor\SecondFactorEnrollmentService;
use App\Security\SecondFactor\SecondFactorVerifier;
use App\Security\SecondFactor\TotpEngine;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use LogicException;
use Mockery;
use Mockery\Expectation;
use RuntimeException;
use Tests\TestCase;

class AdministratorLifecycleSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-08-12 12:00:00 UTC');
        Queue::fake();
        config(['administrator-security.verification.delay_seconds' => [0, 0, 0, 0, 0]]);
    }

    public function test_promotion_audit_or_required_intent_failure_leaves_no_request_or_privilege(): void
    {
        [$administrator, , $adminSecret] = $this->confirmedUser(true);
        [$target] = $this->confirmedUser();
        $session = $this->freshProof($administrator, $adminSecret, 'administrator.promotion.initiate');
        $audit = Mockery::mock(AuditEventRecorder::class);
        $expectation = $audit->shouldReceive('record');
        assert($expectation instanceof Expectation);
        $expectation->andThrow(new RuntimeException('audit unavailable'));
        $this->app->instance(AuditEventRecorder::class, $audit);

        try {
            app(AdministratorPromotionLifecycle::class)->initiate($administrator, $target, $session);
            $this->fail('Promotion proceeded without audit persistence.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('administrator_promotion_requests', 0);
        }

        $this->app->forgetInstance(AuditEventRecorder::class);
        Date::setTestNow(now()->addSeconds(30));
        $session = $this->freshProof($administrator, $adminSecret, 'administrator.promotion.initiate', false);
        $notifications = Mockery::mock(SecurityNotificationIntentService::class);
        $expectation = $notifications->shouldReceive('create');
        assert($expectation instanceof Expectation);
        $expectation->andThrow(new RuntimeException('intent unavailable'));
        $this->app->instance(SecurityNotificationIntentService::class, $notifications);
        try {
            app(AdministratorPromotionLifecycle::class)->initiate($administrator, $target, $session);
            $this->fail('Promotion proceeded without notification intent persistence.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('administrator_promotion_requests', 0);
            $this->assertFalse($target->refresh()->isAdministrator());
        }
    }

    public function test_revocation_audit_failure_rolls_back_but_notification_failure_does_not_preserve_dangerous_access(): void
    {
        [$actor, , $actorSecret] = $this->confirmedUser(true);
        [$target] = $this->confirmedUser(true);
        $session = $this->freshProof($actor, $actorSecret, 'administrator.revoke');
        $audit = Mockery::mock(AuditEventRecorder::class);
        $expectation = $audit->shouldReceive('record');
        assert($expectation instanceof Expectation);
        $expectation->andThrow(new RuntimeException('audit unavailable'));
        $this->app->instance(AuditEventRecorder::class, $audit);
        try {
            app(AdministratorRevocation::class)->revoke($actor, $target, $session);
            $this->fail('Revocation proceeded without mandatory audit evidence.');
        } catch (RuntimeException) {
            $this->assertTrue($target->refresh()->isAdministrator());
        }

        $this->app->forgetInstance(AuditEventRecorder::class);
        Date::setTestNow(now()->addSeconds(30));
        $session = $this->freshProof($actor, $actorSecret, 'administrator.revoke', false);
        $notifications = Mockery::mock(SecurityNotificationIntentService::class);
        $expectation = $notifications->shouldReceive('create');
        assert($expectation instanceof Expectation);
        $expectation->andThrow(new RuntimeException('intent unavailable'));
        $this->app->instance(SecurityNotificationIntentService::class, $notifications);
        app(AdministratorRevocation::class)->revoke($actor, $target, $session);
        $this->assertFalse($target->refresh()->isAdministrator());
    }

    public function test_serial_revocations_cannot_remove_all_administrators(): void
    {
        [$first, , $firstSecret] = $this->confirmedUser(true);
        [$second, , $secondSecret] = $this->confirmedUser(true);
        app(AdministratorRevocation::class)->revoke($first, $second, $this->freshProof($first, $firstSecret, 'administrator.revoke'));

        Date::setTestNow(now()->addSeconds(30));
        try {
            app(AdministratorRevocation::class)->revoke($second->fresh(), $first, $this->freshProof($second->fresh(), $secondSecret, 'administrator.revoke', false));
        } catch (RuntimeException) {
            $this->assertSame(1, User::query()->where('is_administrator', true)->count());
            $this->assertTrue($first->refresh()->isAdministrator());
        }
    }

    public function test_non_sole_administrator_passes_account_deletion_guard(): void
    {
        [$first] = $this->confirmedUser(true);
        $this->confirmedUser(true);
        DB::transaction(fn () => app(LastAdministratorGuard::class)->assertAccountDeletionAllowed($first));
        $this->addToAssertionCount(1);
    }

    public function test_normal_seeder_creates_no_admin_and_test_factory_cannot_become_a_production_path(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertSame(0, User::query()->where('is_administrator', true)->count());
        app()->detectEnvironment(fn () => 'production');
        $this->expectException(LogicException::class);
        User::factory()->administrator()->create();
    }

    public function test_bootstrap_and_break_glass_have_no_http_route_and_declined_bootstrap_is_audited_without_secrets(): void
    {
        [$target] = $this->confirmedUser();
        config([
            'administrator-security.lifecycle.bootstrap.enabled' => true,
            'administrator-security.lifecycle.bootstrap.expected_environment' => 'testing',
            'administrator-security.lifecycle.bootstrap.target_email' => $target->email,
            'administrator-security.lifecycle.bootstrap.operator_reference' => 'operator:decline-test',
            'administrator-security.lifecycle.bootstrap.operation_version' => 'test-version',
        ]);
        $this->assertFalse(collect(Route::getRoutes())->contains(fn ($route) => str_contains($route->uri(), 'bootstrap') || str_contains($route->uri(), 'break-glass')));
        $this->artisan('administrator:bootstrap')
            ->expectsConfirmation('Confirm this exact target and environment for one-time administrator activation?', 'no')
            ->assertFailed();
        $event = AuditEvent::query()->where('action', 'administrator.bootstrap_refused')->firstOrFail();
        $encoded = json_encode($event->payload);
        $this->assertSame('operator_declined', $event->payload['refusal_reason_code']);
        $this->assertStringNotContainsString('password', strtolower($encoded));
        $this->assertStringNotContainsString('totp', strtolower($encoded));
        $this->assertStringNotContainsString('recovery', strtolower($encoded));
    }

    /** @return array{User, SecondFactor, string} */
    private function confirmedUser(bool $administrator = false): array
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'password' => 'correct-password']);
        $enrollment = app(SecondFactorEnrollmentService::class);
        $presentation = $enrollment->begin($user, 'correct-password', app(RecentAuthentication::class), app('session.store'));
        $enrollment->confirm($user, app(TotpEngine::class)->codeAt($presentation->manualKey, app(TotpEngine::class)->currentTimestep()), '192.0.2.5');
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
        if (! app(SecondFactorVerifier::class)->verify($user, $code, $operation, '192.0.2.20')->succeeded()) {
            throw new RuntimeException('Unable to create the test-only fresh proof.');
        }
        app(RecentAuthentication::class)->rememberFreshFactor($user, $operation, $session);

        return $session;
    }
}
