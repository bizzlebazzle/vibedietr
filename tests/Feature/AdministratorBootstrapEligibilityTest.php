<?php

namespace Tests\Feature;

use App\Models\SecondFactor;
use App\Models\User;
use App\Security\SecondFactor\RecentAuthentication;
use App\Security\SecondFactor\SecondFactorEnrollmentService;
use App\Security\SecondFactor\TotpEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdministratorBootstrapEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-08-12 12:00:00 UTC');
        Queue::fake();
    }

    public function test_missing_enablement_or_wrong_environment_refuses_without_database_change(): void
    {
        [$target] = $this->confirmedUser();
        config([
            'administrator-security.lifecycle.bootstrap.enabled' => false,
            'administrator-security.lifecycle.bootstrap.expected_environment' => 'testing',
            'administrator-security.lifecycle.bootstrap.target_email' => $target->email,
            'administrator-security.lifecycle.bootstrap.operator_reference' => 'operator:eligibility-test',
        ]);
        $this->artisan('administrator:bootstrap')->assertFailed();
        $this->assertFalse($target->refresh()->isAdministrator());

        config(['administrator-security.lifecycle.bootstrap.enabled' => true, 'administrator-security.lifecycle.bootstrap.expected_environment' => 'production']);
        $this->artisan('administrator:bootstrap')->assertFailed();
        $this->assertFalse($target->refresh()->isAdministrator());
    }

    public function test_existing_administrator_blocks_bootstrap_of_an_otherwise_eligible_target(): void
    {
        User::factory()->administrator()->create();
        [$target] = $this->confirmedUser();
        $this->configure($target);

        $this->artisan('administrator:bootstrap')
            ->expectsConfirmation('Confirm this exact target and environment for one-time administrator activation?', 'yes')
            ->assertFailed();
        $this->assertFalse($target->refresh()->isAdministrator());
    }

    public function test_unverified_or_unenrolled_target_is_refused(): void
    {
        [$unverified] = $this->confirmedUser();
        $unverified->forceFill(['email_verified_at' => null])->save();
        $this->configure($unverified);
        $this->artisan('administrator:bootstrap')
            ->expectsConfirmation('Confirm this exact target and environment for one-time administrator activation?', 'yes')
            ->assertFailed();
        $this->assertFalse($unverified->refresh()->isAdministrator());

        $unenrolled = User::factory()->create(['email_verified_at' => now()]);
        $this->configure($unenrolled);
        $this->artisan('administrator:bootstrap')
            ->expectsConfirmation('Confirm this exact target and environment for one-time administrator activation?', 'yes')
            ->assertFailed();
        $this->assertFalse($unenrolled->refresh()->isAdministrator());
    }

    private function configure(User $target): void
    {
        config([
            'administrator-security.lifecycle.bootstrap.enabled' => true,
            'administrator-security.lifecycle.bootstrap.expected_environment' => 'testing',
            'administrator-security.lifecycle.bootstrap.target_email' => $target->email,
            'administrator-security.lifecycle.bootstrap.operator_reference' => 'operator:eligibility-test',
            'administrator-security.lifecycle.bootstrap.operation_version' => 'test-version',
        ]);
    }

    /** @return array{User, SecondFactor, string} */
    private function confirmedUser(): array
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'password' => 'correct-password']);
        $enrollment = app(SecondFactorEnrollmentService::class);
        $presentation = $enrollment->begin($user, 'correct-password', app(RecentAuthentication::class), app('session.store'));
        $enrollment->confirm($user, app(TotpEngine::class)->codeAt($presentation->manualKey, app(TotpEngine::class)->currentTimestep()), '192.0.2.5');
        $factor = $enrollment->acknowledgeRecoveryCodes($user);

        return [$user->fresh(), $factor, $presentation->manualKey];
    }
}
