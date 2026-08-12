<?php

namespace App\Administrator;

use App\Models\AdministratorLifecycleState;
use App\Models\User;
use RuntimeException;

final class LastAdministratorGuard
{
    public function assertAccountDeletionAllowed(User $user): void
    {
        AdministratorLifecycleState::query()->lockForUpdate()->findOrFail(1);
        User::query()->where('is_administrator', true)->orderBy('id')->lockForUpdate()->get();
        $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());

        if ($locked->isAdministrator() && User::query()->where('is_administrator', true)->count() <= 1) {
            throw new RuntimeException('The sole administrator cannot delete their account until a replacement is active.');
        }
    }
}
