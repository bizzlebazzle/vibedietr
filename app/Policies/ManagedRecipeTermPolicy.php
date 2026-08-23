<?php

namespace App\Policies;

use App\Models\ManagedRecipeTerm;
use App\Models\User;

class ManagedRecipeTermPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('access-admin');
    }

    public function create(User $user): bool
    {
        return $user->can('access-admin');
    }

    public function update(User $user, ManagedRecipeTerm $term): bool
    {
        return $user->can('access-admin');
    }
}
