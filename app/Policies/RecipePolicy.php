<?php

namespace App\Policies;

use App\Models\Recipe;
use App\Models\User;

class RecipePolicy
{
    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Recipe $recipe): bool
    {
        return $user->getKey() === $recipe->user_id;
    }

    public function update(User $user, Recipe $recipe): bool
    {
        return $user->getKey() === $recipe->user_id;
    }
}
