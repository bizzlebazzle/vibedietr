<?php

namespace App\Security\SecondFactor;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

final class RecentAuthentication
{
    private const PRIMARY_KEY = 'auth.password_confirmed_at';

    private const FRESH_FACTOR_KEY = 'auth.second_factor_proof';

    public function confirmPrimary(User $user, #[SensitiveParameter] string $password, Session $session): bool
    {
        if (! Hash::check($password, $user->password)) {
            return false;
        }

        $session->put(self::PRIMARY_KEY, Date::now()->getTimestamp());

        return true;
    }

    public function hasRecentPrimary(Session $session): bool
    {
        $confirmedAt = $session->get(self::PRIMARY_KEY);

        return is_int($confirmedAt)
            && $confirmedAt >= Date::now()->subSeconds((int) config('administrator-security.primary_authentication_ttl_seconds'))->getTimestamp();
    }

    public function rememberFreshFactor(User $user, string $operation, Session $session): void
    {
        $session->put(self::FRESH_FACTOR_KEY, [
            'user_id' => (int) $user->getKey(),
            'operation' => $operation,
            'expires_at' => Date::now()->addSeconds((int) config('administrator-security.totp.fresh_proof_ttl_seconds'))->getTimestamp(),
        ]);
    }

    public function consumeFreshFactor(User $user, string $operation, Session $session): bool
    {
        $proof = $session->pull(self::FRESH_FACTOR_KEY);

        return is_array($proof)
            && ($proof['user_id'] ?? null) === (int) $user->getKey()
            && ($proof['operation'] ?? null) === $operation
            && is_int($proof['expires_at'] ?? null)
            && $proof['expires_at'] >= Date::now()->getTimestamp();
    }

    public function clear(Session $session): void
    {
        $session->forget([self::PRIMARY_KEY, self::FRESH_FACTOR_KEY]);
    }
}
