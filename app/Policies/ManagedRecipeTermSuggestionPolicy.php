<?php

namespace App\Policies;

use App\Models\ManagedRecipeTermSuggestion;
use App\Models\User;

class ManagedRecipeTermSuggestionPolicy
{
    public function review(User $user, ManagedRecipeTermSuggestion $suggestion): bool
    {
        return $user->getKey() === $suggestion->recipe()->value('user_id');
    }
}
