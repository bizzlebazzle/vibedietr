<?php

namespace App\Policies;

use App\Models\RecipeCollection;
use App\Models\User;

class RecipeCollectionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, RecipeCollection $collection): bool
    {
        return $user->getKey() === $collection->user_id;
    }

    public function update(User $user, RecipeCollection $collection): bool
    {
        return $this->view($user, $collection);
    }

    public function delete(User $user, RecipeCollection $collection): bool
    {
        return $this->view($user, $collection);
    }
}
