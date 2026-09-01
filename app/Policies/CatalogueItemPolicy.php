<?php

namespace App\Policies;

use App\Domain\Catalogue\CatalogueVisibility;
use App\Models\CatalogueItem;
use App\Models\User;

class CatalogueItemPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, CatalogueItem $item): bool
    {
        return app(CatalogueVisibility::class)->allows($user, $item);
    }

    public function update(?User $user, CatalogueItem $item): bool
    {
        return false;
    }

    public function delete(?User $user, CatalogueItem $item): bool
    {
        return false;
    }

    public function moderate(User $user, CatalogueItem $item): bool
    {
        return $user->can('access-admin');
    }
}
