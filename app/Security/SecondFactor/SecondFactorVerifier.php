<?php

namespace App\Security\SecondFactor;

use App\Models\SecondFactor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Throwable;

final class SecondFactorVerifier
{
    public function __construct(
        private readonly TotpEngine $totp,
        private readonly SecondFactorSecretStore $secrets,
        private readonly VerificationThrottle $throttle,
    ) {}

    public function verify(
        User $user,
        #[SensitiveParameter] string $code,
        string $operation,
        string $sourceIp,
    ): SecondFactorResult {
        $factor = SecondFactor::query()->where('user_id', $user->getKey())->first();

        if ($factor === null) {
            return new SecondFactorResult(SecondFactorStatus::Invalid);
        }

        $limited = $this->throttle->check($user, $factor, $operation, $sourceIp);

        if ($limited !== null) {
            return new SecondFactorResult($limited);
        }

        if (! preg_match('/^\d{6}$/D', $code)) {
            return new SecondFactorResult($this->throttle->failed($user, $factor, $operation, $sourceIp));
        }

        try {
            $secret = $this->secrets->decrypt($factor->encrypted_secret);
            $matched = $this->totp->match($secret, $code, (int) config('administrator-security.totp.window'));

            if ($matched === false) {
                $wideMatch = $this->totp->match($secret, $code, 10);
                $status = $wideMatch === false ? SecondFactorStatus::Invalid : SecondFactorStatus::Expired;
                $this->throttle->failed($user, $factor, $operation, $sourceIp);

                return new SecondFactorResult($status);
            }

            $consumed = DB::transaction(function () use ($factor, $matched): bool {
                return SecondFactor::query()
                    ->whereKey($factor->getKey())
                    ->where(function ($query) use ($matched) {
                        $query->whereNull('last_consumed_timestep')->orWhere('last_consumed_timestep', '<', $matched);
                    })
                    ->update(['last_consumed_timestep' => $matched, 'consecutive_failures' => 0, 'locked_until' => null]) === 1;
            });

            if (! $consumed) {
                $this->throttle->failed($user, $factor, $operation, $sourceIp);

                return new SecondFactorResult(SecondFactorStatus::Replayed);
            }

            $this->throttle->succeeded($user);

            return new SecondFactorResult(SecondFactorStatus::Verified, $matched);
        } catch (Throwable) {
            return new SecondFactorResult(SecondFactorStatus::Unavailable);
        }
    }
}
