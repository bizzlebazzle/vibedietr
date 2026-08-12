<?php

namespace App\Security\SecondFactor;

use App\Models\SecondFactor;
use App\Models\SecondFactorRecoveryCode;
use App\Models\User;
use App\Security\Notifications\SecurityEventType;
use App\Security\Notifications\SecurityNotificationIntentService;
use App\Security\SecurityAuditService;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

final class SecondFactorRecoveryService
{
    public function __construct(
        private readonly VerificationThrottle $throttle,
        private readonly SecurityAuditService $audit,
        private readonly SecurityNotificationIntentService $notifications,
    ) {}

    public function useRecoveryCode(
        User $user,
        #[SensitiveParameter] string $password,
        #[SensitiveParameter] string $value,
        string $sourceIp,
        Session $session,
    ): bool {
        $factor = SecondFactor::query()->where('user_id', $user->getKey())->first();
        if ($this->throttle->check($user, $factor, 'recovery_code', $sourceIp) !== null) {
            return false;
        }
        if (! Hash::check($password, $user->password)) {
            $this->throttle->failed($user, $factor, 'recovery_code', $sourceIp);

            return false;
        }

        if ($factor === null) {
            $this->throttle->failed($user, null, 'recovery_code', $sourceIp);

            return false;
        }

        foreach ($factor->recoveryCodes()->whereNull('used_at')->get() as $code) {
            /** @var \App\Models\SecondFactorRecoveryCode $code */
            if (! Hash::check($value, $code->code_hash)) {
                continue;
            }

            $consumed = SecondFactorRecoveryCode::query()->whereKey($code->getKey())->whereNull('used_at')->update(['used_at' => Date::now()]);

            if ($consumed === 1) {
                $correlationId = strtolower((string) \Illuminate\Support\Str::ulid());
                $this->throttle->succeeded($user);
                $session->put('auth.second_factor_recovery', [
                    'user_id' => (int) $user->getKey(),
                    'correlation_id' => $correlationId,
                    'expires_at' => Date::now()->addSeconds((int) config('administrator-security.totp.recovery_session_ttl_seconds'))->getTimestamp(),
                ]);
                $this->audit->factor($user, $user, 'recovery_code_used', 'completed', 'factor_recovery', $correlationId);
                $this->notifications->create(SecurityEventType::RecoveryCodeUsed, $user, $correlationId);

                return true;
            }
        }

        $this->throttle->failed($user, $factor, 'recovery_code', $sourceIp);

        return false;
    }

    public function hasRecoverySession(User $user, Session $session): bool
    {
        $proof = $session->get('auth.second_factor_recovery');

        return is_array($proof)
            && ($proof['user_id'] ?? null) === (int) $user->getKey()
            && is_int($proof['expires_at'] ?? null)
            && $proof['expires_at'] >= Date::now()->getTimestamp();
    }

    public function authorizationId(User $user, Session $session): ?string
    {
        if (! $this->hasRecoverySession($user, $session)) {
            return null;
        }

        $proof = $session->get('auth.second_factor_recovery');

        return is_string($proof['authorization_id'] ?? null) ? $proof['authorization_id'] : null;
    }
}
