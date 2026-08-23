<?php

namespace App\Policies;

use App\Domain\Recipes\RecipeLifecycle;
use App\Models\Recipe;
use App\Models\User;

class RecipePolicy
{
    public function create(User $user): bool
    {
        return true;
    }

    public function view(?User $user, Recipe $recipe): bool
    {
        return $recipe->isPubliclyViewable()
            || ($user !== null && $user->getKey() === $recipe->user_id);
    }

    public function remix(User $user, Recipe $recipe): bool
    {
        return $recipe->isFinalized() && $this->view($user, $recipe);
    }

    public function update(User $user, Recipe $recipe): bool
    {
        return $user->getKey() === $recipe->user_id
            && ($recipe->getRawOriginal('lifecycle') === RecipeLifecycle::Draft->value
                || ($recipe->isFinalized() && $recipe->activeRevision()->exists()));
    }

    public function startRevision(User $user, Recipe $recipe): bool
    {
        return $user->getKey() === $recipe->user_id && $recipe->isFinalized();
    }

    public function publishRevision(User $user, Recipe $recipe): bool
    {
        return $user->getKey() === $recipe->user_id && $recipe->isFinalized();
    }

    public function abandonRevision(User $user, Recipe $recipe): bool
    {
        return $user->getKey() === $recipe->user_id && $recipe->isFinalized();
    }

    public function finalize(User $user, Recipe $recipe): bool
    {
        return $user->getKey() === $recipe->user_id;
    }

    public function changeVisibility(User $user, Recipe $recipe): bool
    {
        return $user->getKey() === $recipe->user_id
            && $recipe->isFinalized();
    }
}
