<?php

namespace App\Security\SecondFactor;

use App\Models\SecondFactorRecoveryAuthorization;
use App\Models\User;
use Illuminate\Contracts\Session\Session;

final class PrivilegedWorkflowGuard
{
    public function __construct(private readonly RecentAuthentication $authentication) {}

    public function allows(?User $user, string $operation, Session $session, bool $consume = true): bool
    {
        if ($user === null || ! $user->isAdministrator() || ! $user->hasConfirmedSecondFactor()) {
            return false;

        }
        $recoveryPending = SecondFactorRecoveryAuthorization::query()
            ->where('target_user_id', $user->getKey())
            ->whereNull('consumed_at')
            ->whereNull('cancelled_at')
            ->where('expires_at', '>=', now())
            ->exists();
        if ($recoveryPending) {
            return false;
        }

        if (! $this->authentication->hasRecentPrimary($session)) {
            return false;
        }

        return $consume
            ? $this->authentication->consumeFreshFactor($user, $operation, $session)
            : $this->hasFreshProofWithoutConsuming($user, $operation, $session);
    }

    private function hasFreshProofWithoutConsuming(User $user, string $operation, Session $session): bool
    {
        $proof = $session->get('auth.second_factor_proof');

        return is_array($proof)
            && ($proof['user_id'] ?? null) === (int) $user->getKey()
            && ($proof['operation'] ?? null) === $operation
            && is_int($proof['expires_at'] ?? null)
            && $proof['expires_at'] >= now()->getTimestamp();
    }
}
