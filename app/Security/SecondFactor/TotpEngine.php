<?php

namespace App\Security\SecondFactor;

use Illuminate\Support\Facades\Date;
use PragmaRX\Google2FA\Google2FA;
use SensitiveParameter;

final class TotpEngine
{
    public function __construct(private readonly Google2FA $google2fa)
    {
        $this->google2fa->setAlgorithm((string) config('administrator-security.totp.algorithm'));
        $this->google2fa->setOneTimePasswordLength((int) config('administrator-security.totp.digits'));
        $this->google2fa->setKeyRegeneration((int) config('administrator-security.totp.period'));
        $this->google2fa->setWindow((int) config('administrator-security.totp.window'));
    }

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey((int) config('administrator-security.totp.secret_length'));
    }

    public function currentTimestep(): int
    {
        return intdiv(Date::now()->getTimestamp(), (int) config('administrator-security.totp.period'));
    }

    public function match(#[SensitiveParameter] string $secret, #[SensitiveParameter] string $code, int $window = 1): int|false
    {
        $current = $this->currentTimestep();

        for ($candidate = $current - $window; $candidate <= $current + $window; $candidate++) {
            if (hash_equals($this->codeAt($secret, $candidate), $code)) {
                return $candidate;
            }
        }

        return false;
    }

    public function codeAt(#[SensitiveParameter] string $secret, int $timestep): string
    {
        return $this->google2fa->oathTotp($secret, $timestep);
    }

    public function provisioningUri(string $accountLabel, #[SensitiveParameter] string $secret): string
    {
        return $this->google2fa->getQRCodeUrl((string) config('administrator-security.totp.issuer'), $accountLabel, $secret);
    }
}
