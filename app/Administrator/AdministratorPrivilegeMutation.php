<?php

namespace App\Administrator;

use App\Models\User;

final class AdministratorPrivilegeMutation
{
    private static int $depth = 0;

    public function set(User $user, bool $administrator): void
    {
        if ($administrator && ! $user->isActive()) {
            throw new \RuntimeException('An inactive account cannot become an administrator.');
        }
        self::$depth++;
        try {
            $user->forceFill(['is_administrator' => $administrator])->save();
        } finally {
            self::$depth--;
        }
    }

    public static function isAuthorized(): bool
    {
        return self::$depth > 0;
    }
}
