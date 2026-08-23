<?php

namespace App\Domain\Recipes;

enum ManagedRecipeTermSuggestionStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
