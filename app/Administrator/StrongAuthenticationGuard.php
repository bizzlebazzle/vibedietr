<?php

namespace App\Administrator;

use App\Models\User;
use App\Security\SecondFactor\RecentAuthentication;
use Illuminate\Contracts\Session\Session;

final class StrongAuthenticationGuard
{
    public function __construct(private readonly RecentAuthentication $authentication) {}

    public function consume(User $user, string $operation, Session $session): bool
    {
        return $user->hasConfirmedSecondFactor()
            && $this->authentication->hasRecentPrimary($session)
            && $this->authentication->consumeFreshFactor($user, $operation, $session);
    }
}
