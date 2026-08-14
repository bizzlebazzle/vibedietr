<?php

namespace Tests\Feature;

use App\Administrator\AdministratorBreakGlassReplacement;
use App\Audit\AuditEventRecorder;
use App\Models\AdministratorLifecycleState;
use App\Models\SecondFactor;
use App\Models\User;
use App\Security\Notifications\SecurityNotificationIntentService;
use App\Security\SecondFactor\RecentAuthentication;
use App\Security\SecondFactor\SecondFactorEnrollmentService;
use App\Security\SecondFactor\TotpEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\Expectation;
use RuntimeException;
use Tests\TestCase;

class AdministratorBreakGlassTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-08-12 12:00:00 UTC');
        Queue::fake();
    }

    public function test_break_glass_requires_separate_configuration_completed_bootstrap_and_confirmation(): void
    {
        [$compromised] = $this->confirmedUser(true);
        [$replacement] = $this->confirmedUser();
        $this->configure($compromised, $replacement);

        try {
            app(AdministratorBreakGlassReplacement::class)->execute();
            $this->fail('Break-glass ran before initial bootstrap completion.');
        } catch (RuntimeException) {
            $this->assertFalse($replacement->refresh()->isAdministrator());
        }

        AdministratorLifecycleState::query()->findOrFail(1)->forceFill(['bootstrap_completed_at' => now()])->save();
        $this->artisan('administrator:break-glass-replace')
            ->expectsConfirmation('Confirm this exact emergency replacement operation?', 'no')
            ->assertFailed();
        $this->assertTrue($compromised->refresh()->isAdministrator());
        $this->assertFalse($replacement->refresh()->isAdministrator());
    }

    public function test_break_glass_refuses_while_an_unconfigured_usable_administrator_remains(): void
    {
        [$compromised] = $this->confirmedUser(true);
        $this->confirmedUser(true);
        [$replacement] = $this->confirmedUser();
        $this->configure($compromised, $replacement);
        AdministratorLifecycleState::query()->findOrFail(1)->forceFill(['bootstrap_completed_at' => now()])->save();

        $this->expectException(RuntimeException::class);
        app(AdministratorBreakGlassReplacement::class)->execute();
    }

    public function test_break_glass_audit_or_required_notification_failure_rolls_back_both_roles_and_marker(): void
    {
        [$compromised] = $this->confirmedUser(true);
        [$replacement] = $this->confirmedUser();
        $this->configure($compromised, $replacement);
        $state = AdministratorLifecycleState::query()->findOrFail(1);
        $state->forceFill(['bootstrap_completed_at' => now(), 'bootstrap_correlation_id' => 'original'])->save();

        $audit = Mockery::mock(AuditEventRecorder::class);
        $expectation = $audit->shouldReceive('record');
        assert($expectation instanceof Expectation);
        $expectation->andThrow(new RuntimeException('audit unavailable'));
        $this->app->instance(AuditEventRecorder::class, $audit);
        try {
            app(AdministratorBreakGlassReplacement::class)->execute();
            $this->fail('Break-glass proceeded without audit.');
        } catch (RuntimeException) {
            $this->assertTrue($compromised->refresh()->isAdministrator());
            $this->assertFalse($replacement->refresh()->isAdministrator());
        }

        $this->app->forgetInstance(AuditEventRecorder::class);
        $notifications = Mockery::mock(SecurityNotificationIntentService::class);
        $expectation = $notifications->shouldReceive('create');
        assert($expectation instanceof Expectation);
        $expectation->andThrow(new RuntimeException('intent unavailable'));
        $this->app->instance(SecurityNotificationIntentService::class, $notifications);
        try {
            app(AdministratorBreakGlassReplacement::class)->execute();
            $this->fail('Break-glass proceeded without notification intents.');
        } catch (RuntimeException) {
            $this->assertTrue($compromised->refresh()->isAdministrator());
            $this->assertFalse($replacement->refresh()->isAdministrator());
            $this->assertSame('original', $state->fresh()->bootstrap_correlation_id);
        }
    }

    private function configure(User $compromised, User $replacement): void
    {
        config([
            'administrator-security.lifecycle.break_glass.enabled' => true,
            'administrator-security.lifecycle.break_glass.expected_environment' => 'testing',
            'administrator-security.lifecycle.break_glass.replacement_email' => $replacement->email,
            'administrator-security.lifecycle.break_glass.compromised_email' => $compromised->email,
            'administrator-security.lifecycle.break_glass.operator_reference' => 'operator:break-glass-test',
        ]);
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
}
