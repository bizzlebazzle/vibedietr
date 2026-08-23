<?php

namespace App\Policies;

use App\Models\PrivateRecipeTag;
use App\Models\User;

class PrivateRecipeTagPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, PrivateRecipeTag $tag): bool
    {
        return $user->getKey() === $tag->user_id;
    }

    public function update(User $user, PrivateRecipeTag $tag): bool
    {
        return $this->view($user, $tag);
    }

    public function delete(User $user, PrivateRecipeTag $tag): bool
    {
        return $this->view($user, $tag);
    }
}
