<?php

namespace App\Security\SecondFactor;

use App\Models\SecondFactor;
use App\Models\SecondFactorEnrollment;
use App\Models\SecondFactorRecoveryAuthorization;
use App\Models\SecondFactorRecoveryCode;
use App\Models\User;
use App\Security\Notifications\SecurityEventType;
use App\Security\Notifications\SecurityNotificationIntentService;
use App\Security\SecurityAuditService;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;

final class SecondFactorEnrollmentService
{
    public function __construct(
        private readonly TotpEngine $totp,
        private readonly QrCodeRenderer $qr,
        private readonly SecondFactorSecretStore $secrets,
        private readonly VerificationThrottle $throttle,
        private readonly SecurityAuditService $audit,
        private readonly SecurityNotificationIntentService $notifications,
    ) {}

    public function begin(User $user, #[SensitiveParameter] string $password, RecentAuthentication $authentication, Session $session): EnrollmentPresentation
    {
        if ($user->email_verified_at === null || SecondFactor::query()->where('user_id', $user->getKey())->exists()) {
            throw new RuntimeException('Second-factor enrollment is not available for this account.');
        }

        if (! $authentication->confirmPrimary($user, $password, $session)) {
            throw new RuntimeException('Immediate password confirmation failed.');
        }

        SecondFactorEnrollment::query()->where('user_id', $user->getKey())->delete();
        $secret = $this->totp->generateSecret();
        $enrollment = new SecondFactorEnrollment;
        $enrollment->forceFill([
            'user_id' => $user->getKey(),
            'encrypted_secret' => $this->secrets->encrypt($secret),
            'purpose' => 'enrollment',
            'expires_at' => Date::now()->addSeconds((int) config('administrator-security.totp.enrollment_ttl_seconds')),
        ]);
        $enrollment->save();
        $uri = $this->totp->provisioningUri($user->email, $secret);

        return new EnrollmentPresentation($secret, $uri, $this->qr->render($uri));
    }

    public function beginRecoveryReplacement(User $user, SecondFactorRecoveryService $recovery, Session $session): EnrollmentPresentation
    {
        if ($user->email_verified_at === null || ! SecondFactor::query()->where('user_id', $user->getKey())->exists() || ! $recovery->hasRecoverySession($user, $session)) {
            throw new RuntimeException('Replacement-factor enrollment is not available for this account.');
        }

        SecondFactorEnrollment::query()->where('user_id', $user->getKey())->delete();
        $secret = $this->totp->generateSecret();
        $enrollment = new SecondFactorEnrollment;
        $enrollment->forceFill([
            'user_id' => $user->getKey(),
            'encrypted_secret' => $this->secrets->encrypt($secret),
            'recovery_authorization_id' => $recovery->authorizationId($user, $session),
            'purpose' => 'recovery',
            'expires_at' => Date::now()->addSeconds((int) config('administrator-security.totp.enrollment_ttl_seconds')),
        ]);
        $enrollment->save();
        $uri = $this->totp->provisioningUri($user->email, $secret);

        return new EnrollmentPresentation($secret, $uri, $this->qr->render($uri));
    }

    public function confirm(User $user, #[SensitiveParameter] string $code, string $sourceIp): RecoveryCodeSet|SecondFactorResult
    {
        $enrollment = SecondFactorEnrollment::query()->where('user_id', $user->getKey())->first();

        if ($enrollment === null || Date::parse($enrollment->expires_at)->isPast()) {
            $enrollment?->delete();

            return new SecondFactorResult(SecondFactorStatus::Expired);
        }

        $limited = $this->throttle->check($user, null, 'enrollment_confirmation', $sourceIp);

        if ($limited !== null) {
            return new SecondFactorResult($limited);
        }

        if (! preg_match('/^\d{6}$/D', $code)) {
            return new SecondFactorResult($this->throttle->failed($user, null, 'enrollment_confirmation', $sourceIp));
        }

        $secret = $this->secrets->decrypt($enrollment->encrypted_secret);
        $matched = $this->totp->match($secret, $code, (int) config('administrator-security.totp.window'));

        if ($matched === false) {
            $this->throttle->failed($user, null, 'enrollment_confirmation', $sourceIp);

            return new SecondFactorResult($this->totp->match($secret, $code, 10) === false ? SecondFactorStatus::Invalid : SecondFactorStatus::Expired);
        }

        return DB::transaction(function () use ($enrollment, $matched, $user): RecoveryCodeSet|SecondFactorResult {
            $pending = SecondFactorEnrollment::query()->lockForUpdate()->findOrFail($enrollment->getKey());

            if ($pending->verified_timestep !== null) {
                return new SecondFactorResult(SecondFactorStatus::Replayed);
            }

            $codes = $this->newRecoveryCodes();
            $pending->forceFill(['verified_timestep' => $matched, 'recovery_codes_generated_at' => Date::now()])->save();

            foreach ($codes as $value) {
                $record = new SecondFactorRecoveryCode;
                $record->forceFill(['id' => $record->newUniqueId(), 'enrollment_id' => $pending->getKey(), 'factor_id' => null, 'code_hash' => Hash::make($value)]);
                $record->save();
            }

            $this->throttle->succeeded($user);

            return new RecoveryCodeSet($codes);
        });
    }

    public function acknowledgeRecoveryCodes(User $user, ?Session $session = null): SecondFactor
    {
        return DB::transaction(function () use ($user, $session): SecondFactor {
            $enrollment = SecondFactorEnrollment::query()->where('user_id', $user->getKey())->lockForUpdate()->first();

            if ($enrollment === null || Date::parse($enrollment->expires_at)->isPast() || $enrollment->verified_timestep === null || $enrollment->recoveryCodes()->count() !== 10) {
                throw new RuntimeException('Enrollment is not ready for activation.');
            }

            if ($enrollment->purpose === 'recovery') {
                $existing = SecondFactor::query()->where('user_id', $user->getKey())->lockForUpdate()->firstOrFail();
                $proof = $session?->get('auth.second_factor_recovery');
                $correlationId = is_array($proof) && is_string($proof['correlation_id'] ?? null)
                    ? $proof['correlation_id']
                    : strtolower((string) Str::ulid());

                if ($enrollment->recovery_authorization_id !== null) {
                    $correlationId = (string) SecondFactorRecoveryAuthorization::query()
                        ->whereKey($enrollment->recovery_authorization_id)
                        ->value('correlation_id');
                    $consumed = SecondFactorRecoveryAuthorization::query()
                        ->whereKey($enrollment->recovery_authorization_id)
                        ->where('target_user_id', $user->getKey())
                        ->whereNull('consumed_at')
                        ->whereNull('cancelled_at')
                        ->where('expires_at', '>=', Date::now())
                        ->update(['consumed_at' => Date::now()]);
                    if ($consumed !== 1) {
                        throw new RuntimeException('Recovery authorization is no longer valid.');
                    }
                }
                $existing->delete();
            } elseif (SecondFactor::query()->where('user_id', $user->getKey())->exists()) {
                throw new RuntimeException('Enrollment cannot replace an existing factor.');
            }

            $factor = new SecondFactor;
            $factor->forceFill([
                'id' => $factor->newUniqueId(),
                'user_id' => $user->getKey(),
                'encrypted_secret' => $enrollment->encrypted_secret,
                'last_consumed_timestep' => $enrollment->verified_timestep,
                'confirmed_at' => Date::now(),
                'recovery_codes_acknowledged_at' => Date::now(),
            ]);
            $factor->save();
            $enrollment->recoveryCodes()->update(['factor_id' => $factor->getKey(), 'enrollment_id' => null]);
            $enrollment->delete();

            if ($enrollment->purpose === 'recovery') {
                $user->forceFill(['remember_token' => null])->save();
                if (config('session.driver') === 'database' && $session !== null) {
                    DB::table((string) config('session.table', 'sessions'))
                        ->where('user_id', $user->getKey())
                        ->where('id', '!=', $session->getId())
                        ->delete();
                }
                $session?->forget('auth.second_factor_recovery');

                DB::afterCommit(function () use ($user, $correlationId): void {
                    $this->audit->factor($user, $user, 'factor_replaced', 'completed', 'factor_recovery', $correlationId);
                    $this->notifications->create(SecurityEventType::FactorReplaced, $user, $correlationId);
                });

            }

            return $factor;
        });
    }

    public function cancel(User $user): void
    {
        SecondFactorEnrollment::query()->where('user_id', $user->getKey())->delete();
    }

    /** @return list<string> */
    private function newRecoveryCodes(): array
    {
        $codes = [];

        for ($index = 0; $index < 10; $index++) {
            $raw = strtoupper(bin2hex(random_bytes(16)));
            $codes[] = implode('-', str_split($raw, 8));
        }

        return $codes;
    }
}
