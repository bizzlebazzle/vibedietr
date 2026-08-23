<?php

namespace App\Domain\Profiles;

use App\Models\User;

final class PublicAttributionSnapshot
{
    public function forUser(User $user): ?string
    {
        $name = $user->publicProfile()->value('attribution_name');

        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        return trim($name);
    }
}
