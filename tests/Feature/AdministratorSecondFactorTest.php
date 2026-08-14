<?php

namespace Tests\Feature;

use App\Models\SecondFactor;
use App\Models\SecondFactorRecoveryAuthorization;
use App\Models\SecondFactorRecoveryCode;
use App\Models\User;
use App\Security\SecondFactor\PrivilegedWorkflowGuard;
use App\Security\SecondFactor\RecentAuthentication;
use App\Security\SecondFactor\RecoveryAuthorizationService;
use App\Security\SecondFactor\RecoveryCodeManager;
use App\Security\SecondFactor\RecoveryCodeSet;
use App\Security\SecondFactor\SecondFactorEnrollmentService;
use App\Security\SecondFactor\SecondFactorRecoveryService;
use App\Security\SecondFactor\SecondFactorResult;
use App\Security\SecondFactor\SecondFactorStatus;
use App\Security\SecondFactor\SecondFactorVerifier;
use App\Security\SecondFactor\TotpEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdministratorSecondFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-08-12 12:00:00 UTC');
        Queue::fake();
        config(['administrator-security.verification.delay_seconds' => [0, 0, 0, 0, 0]]);
    }

    public function test_verified_user_can_begin_enrollment_only_after_immediate_password_confirmation(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'password' => 'correct-password']);
        $session = app('session.store');
        $service = app(SecondFactorEnrollmentService::class);

        $this->expectExceptionMessage('Immediate password confirmation failed');
        $service->begin($user, 'wrong-password', app(RecentAuthentication::class), $session);
    }

    public function test_unverified_user_cannot_begin_enrollment(): void
    {
        $user = User::factory()->create(['email_verified_at' => null, 'password' => 'correct-password']);

        $this->expectExceptionMessage('not available');
        app(SecondFactorEnrollmentService::class)->begin($user, 'correct-password', app(RecentAuthentication::class), app('session.store'));
    }

    public function test_enrollment_remains_pending_until_code_and_recovery_acknowledgement(): void
    {
        [$user, $secret] = $this->beginEnrollment();
        $service = app(SecondFactorEnrollmentService::class);

        $this->assertFalse($user->hasConfirmedSecondFactor());
        $incorrect = $service->confirm($user, '000000', '192.0.2.10');
        $this->assertInstanceOf(SecondFactorResult::class, $incorrect);
        $this->assertFalse($user->hasConfirmedSecondFactor());

        $codes = $service->confirm($user, app(TotpEngine::class)->codeAt($secret, app(TotpEngine::class)->currentTimestep()), '192.0.2.10');
        $this->assertInstanceOf(RecoveryCodeSet::class, $codes);
        $this->assertCount(10, $codes->codes);
        $this->assertFalse($user->hasConfirmedSecondFactor());

        $factor = $service->acknowledgeRecoveryCodes($user);
        $this->assertTrue($user->hasConfirmedSecondFactor());
        $this->assertSame(app(TotpEngine::class)->currentTimestep(), $factor->last_consumed_timestep);
        $this->assertArrayNotHasKey('encrypted_secret', $factor->toArray());
    }

    public function test_pending_enrollment_expires_and_cancellation_leaves_no_active_factor(): void
    {
        [$user] = $this->beginEnrollment();
        Date::setTestNow(now()->addMinutes(31));
        $result = app(SecondFactorEnrollmentService::class)->confirm($user, '000000', '192.0.2.10');
        $this->assertSame(SecondFactorStatus::Expired, $result->status);
        $this->assertDatabaseMissing('second_factor_enrollments', ['user_id' => $user->id]);
        $this->assertFalse($user->hasConfirmedSecondFactor());
    }

    public function test_valid_code_succeeds_and_same_timestep_cannot_be_replayed(): void
    {
        [$user, $factor, $secret] = $this->confirmedFactor();
        Date::setTestNow(now()->addSeconds(30));
        $code = app(TotpEngine::class)->codeAt($secret, app(TotpEngine::class)->currentTimestep());
        $verifier = app(SecondFactorVerifier::class);

        $this->assertSame(SecondFactorStatus::Verified, $verifier->verify($user, $code, 'promotion', '192.0.2.20')->status);
        $this->assertSame(SecondFactorStatus::Replayed, $verifier->verify($user, $code, 'revocation', '192.0.2.20')->status);
        $this->assertNotNull($factor->fresh()->last_consumed_timestep);
    }

    public function test_expired_invalid_and_rate_limited_codes_are_distinguished_without_logging_codes(): void
    {
        [$user, , $secret] = $this->confirmedFactor();
        Log::spy();
        $oldCode = app(TotpEngine::class)->codeAt($secret, app(TotpEngine::class)->currentTimestep() - 5);
        $this->assertSame(SecondFactorStatus::Expired, app(SecondFactorVerifier::class)->verify($user, $oldCode, 'promotion', '198.51.100.1')->status);

        foreach (range(1, 4) as $attempt) {
            app(SecondFactorVerifier::class)->verify($user, '999999', 'promotion', '198.51.100.1');
        }

        $this->assertSame(SecondFactorStatus::Throttled, app(SecondFactorVerifier::class)->verify($user, '999999', 'promotion', '198.51.100.1')->status);
    }

    public function test_recent_primary_and_fresh_factor_are_separate_expiring_operation_bound_proofs(): void
    {
        [$user, , $secret] = $this->confirmedFactor(administrator: true);
        $session = app('session.store');
        $recent = app(RecentAuthentication::class);
        $guard = app(PrivilegedWorkflowGuard::class);

        $this->assertFalse($guard->allows($user, 'promotion', $session));
        $this->assertTrue($recent->confirmPrimary($user, 'correct-password', $session));
        $this->assertFalse($guard->allows($user, 'promotion', $session));
        Date::setTestNow(now()->addSeconds(30));
        $code = app(TotpEngine::class)->codeAt($secret, app(TotpEngine::class)->currentTimestep());
        $this->assertTrue(app(SecondFactorVerifier::class)->verify($user, $code, 'promotion', '203.0.113.1')->succeeded());
        $recent->rememberFreshFactor($user, 'promotion', $session);
        $this->assertFalse($guard->allows($user, 'revocation', $session, consume: false));
        $this->assertTrue($guard->allows($user, 'promotion', $session));
        $this->assertFalse($guard->allows($user, 'promotion', $session));

        $recent->confirmPrimary($user, 'correct-password', $session);
        $recent->rememberFreshFactor($user, 'promotion', $session);
        Date::setTestNow(now()->addSeconds(301));
        $this->assertFalse($guard->allows($user, 'promotion', $session));
    }

    public function test_guard_denies_guest_ordinary_user_and_unenrolled_administrator(): void
    {
        $session = app('session.store');
        $guard = app(PrivilegedWorkflowGuard::class);
        $this->assertFalse($guard->allows(null, 'promotion', $session));
        $this->assertFalse($guard->allows(User::factory()->create(), 'promotion', $session));
        $this->assertFalse($guard->allows(User::factory()->administrator()->create(), 'promotion', $session));
    }

    public function test_recovery_code_is_single_use_and_never_becomes_a_privileged_proof(): void
    {
        [$user] = $this->confirmedFactor(administrator: true);
        $plain = 'AAAA1111-BBBB2222-CCCC3333-DDDD4444';
        $factor = $user->secondFactor()->firstOrFail();
        $record = new SecondFactorRecoveryCode;
        $record->forceFill(['id' => $record->newUniqueId(), 'factor_id' => $factor->getKey(), 'code_hash' => Hash::make($plain)]);
        $record->save();
        $session = app('session.store');
        $recovery = app(SecondFactorRecoveryService::class);

        $this->assertFalse($recovery->useRecoveryCode($user, 'wrong-password', $plain, '192.0.2.1', $session));
        $this->assertTrue($recovery->useRecoveryCode($user, 'correct-password', $plain, '192.0.2.1', $session));
        $this->assertFalse($recovery->useRecoveryCode($user, 'correct-password', $plain, '192.0.2.1', $session));
        $this->assertTrue($recovery->hasRecoverySession($user, $session));
        $this->assertFalse(app(PrivilegedWorkflowGuard::class)->allows($user, 'promotion', $session));
    }

    public function test_assisted_recovery_is_target_bound_short_lived_and_restricts_privileged_access(): void
    {
        [$actor, , $actorSecret] = $this->confirmedFactor(administrator: true);
        [$target] = $this->confirmedFactor(administrator: true);
        Date::setTestNow(now()->addSeconds(30));
        $actorCode = app(TotpEngine::class)->codeAt($actorSecret, app(TotpEngine::class)->currentTimestep());
        $actorSession = app('session.store');
        $service = app(RecoveryAuthorizationService::class);

        $authorization = $service->initiateAssisted(
            $actor,
            $target,
            'correct-password',
            $actorCode,
            '192.0.2.40',
            $actorSession,
            '01k2hassistedrecovery00000000',
        );

        $this->assertSame('assisted_administrator', $authorization->method);
        $this->assertFalse(app(PrivilegedWorkflowGuard::class)->allows($target, 'revocation', app('session.store')));
        $targetSession = app('session.store');
        $this->assertFalse($service->establishAssistedSession($actor, $authorization->getKey(), $targetSession));
        $this->assertTrue($service->establishAssistedSession($target, $authorization->getKey(), $targetSession));
        Date::setTestNow(now()->addMinutes(16));
        $this->assertFalse($service->establishAssistedSession($target, $authorization->getKey(), app('session.store')));
    }

    public function test_cli_recovery_authorization_is_hashed_target_bound_expiring_and_single_use_on_replacement(): void
    {
        [$target, $oldFactor] = $this->confirmedFactor(administrator: true);
        $service = app(RecoveryAuthorizationService::class);
        $issued = $service->issueCli($target, 'operator-opaque-reference', '01k2hclirecovery000000000000');
        $authorization = SecondFactorRecoveryAuthorization::query()->findOrFail($issued->id);

        $this->assertArrayNotHasKey('authorization_hash', $authorization->toArray());
        $session = app('session.store');
        $this->assertFalse($service->establishCliSession($target, $issued->id, 'invalid-value', $session));
        $plaintext = $issued->plaintextForInitialDisplay();
        $this->assertTrue($service->establishCliSession($target, $issued->id, $plaintext, $session));

        $enrollment = app(SecondFactorEnrollmentService::class);
        $presentation = $enrollment->beginRecoveryReplacement($target, app(SecondFactorRecoveryService::class), $session);
        $code = app(TotpEngine::class)->codeAt($presentation->manualKey, app(TotpEngine::class)->currentTimestep());
        $this->assertInstanceOf(RecoveryCodeSet::class, $enrollment->confirm($target, $code, '192.0.2.41'));
        $replacement = $enrollment->acknowledgeRecoveryCodes($target, $session);

        $this->assertNotSame($oldFactor->getKey(), $replacement->getKey());
        $this->assertNotNull($authorization->fresh()->consumed_at);
        $this->assertStringNotContainsString($plaintext, (string) json_encode($issued));
        $this->assertFalse($service->establishCliSession($target, $issued->id, $plaintext, app('session.store')));
    }

    public function test_recovery_replacement_keeps_old_factor_active_until_new_factor_and_codes_are_confirmed(): void
    {
        [$user, $oldFactor] = $this->confirmedFactor(administrator: true);
        $plain = 'EEEE5555-FFFF6666-AAAA7777-BBBB8888';
        $record = new SecondFactorRecoveryCode;
        $record->forceFill([
            'id' => $record->newUniqueId(),
            'factor_id' => $oldFactor->getKey(),
            'code_hash' => Hash::make($plain),
        ]);
        $record->save();
        $session = app('session.store');
        $recovery = app(SecondFactorRecoveryService::class);
        $enrollment = app(SecondFactorEnrollmentService::class);

        try {
            $enrollment->beginRecoveryReplacement($user, $recovery, $session);
            $this->fail('Password-only factor replacement was accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($oldFactor->getKey(), $user->secondFactor()->value('id'));
            $this->assertFalse($user->secondFactorEnrollment()->exists());
            $this->assertTrue($recovery->useRecoveryCode($user, 'correct-password', $plain, '192.0.2.30', $session));
        }

        $presentation = $enrollment->beginRecoveryReplacement($user, $recovery, $session);
        $this->assertSame($oldFactor->getKey(), $user->secondFactor()->value('id'));
        $this->assertSame('recovery', $user->secondFactorEnrollment()->value('purpose'));

        $newCode = app(TotpEngine::class)->codeAt($presentation->manualKey, app(TotpEngine::class)->currentTimestep());
        $codes = $enrollment->confirm($user, $newCode, '192.0.2.30');
        $this->assertInstanceOf(RecoveryCodeSet::class, $codes);
        $replacement = $enrollment->acknowledgeRecoveryCodes($user, $session);

        $this->assertNotSame($oldFactor->getKey(), $replacement->getKey());
        $this->assertDatabaseMissing('second_factors', ['id' => $oldFactor->getKey()]);
        $this->assertSame(10, $replacement->recoveryCodes()->count());
        $this->assertFalse($recovery->hasRecoverySession($user, $session));
        $this->assertSame(app(TotpEngine::class)->currentTimestep(), $replacement->last_consumed_timestep);
    }

    public function test_regeneration_requires_password_and_fresh_totp_and_invalidates_the_old_set(): void
    {
        [$user, $factor, $secret] = $this->confirmedFactor(administrator: true);
        $oldHash = $factor->recoveryCodes()->value('code_hash');
        Date::setTestNow(now()->addSeconds(30));
        $totp = app(TotpEngine::class)->codeAt($secret, app(TotpEngine::class)->currentTimestep());

        try {
            app(RecoveryCodeManager::class)->regenerate($user, 'wrong-password', $totp, '192.0.2.8', app('session.store'));
            $this->fail('Password-only fallback was accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('password', $exception->getMessage());
        }

        $replacement = app(RecoveryCodeManager::class)->regenerate($user, 'correct-password', $totp, '192.0.2.8', app('session.store'));
        $this->assertCount(10, $replacement->codes);
        $this->assertSame(10, $factor->recoveryCodes()->count());
        $this->assertFalse($factor->recoveryCodes()->where('code_hash', $oldHash)->exists());
        foreach ($replacement->codes as $code) {
            $this->assertFalse($factor->recoveryCodes()->where('code_hash', $code)->exists());
        }
    }

    /** @return array{User, string} */
    private function beginEnrollment(): array
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'password' => 'correct-password']);
        $presentation = app(SecondFactorEnrollmentService::class)->begin($user, 'correct-password', app(RecentAuthentication::class), app('session.store'));

        return [$user, $presentation->manualKey];
    }

    /** @return array{User, SecondFactor, string} */
    private function confirmedFactor(bool $administrator = false): array
    {
        [$user, $secret] = $this->beginEnrollment();
        if ($administrator) {
            $user->forceFill(['is_administrator' => true])->save();
        }
        $code = app(TotpEngine::class)->codeAt($secret, app(TotpEngine::class)->currentTimestep());
        app(SecondFactorEnrollmentService::class)->confirm($user, $code, '192.0.2.5');
        $factor = app(SecondFactorEnrollmentService::class)->acknowledgeRecoveryCodes($user);

        return [$user->fresh(), $factor, $secret];
    }
}
