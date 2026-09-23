<?php

namespace App\Policies;

use App\Models\NutritionTargetProfile;
use App\Models\User;

class NutritionTargetProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, NutritionTargetProfile $profile): bool
    {
        return $this->owns($user, $profile);
    }

    public function update(User $user, NutritionTargetProfile $profile): bool
    {
        return $this->owns($user, $profile);
    }

    public function delete(User $user, NutritionTargetProfile $profile): bool
    {
        return $this->owns($user, $profile);
    }

    public function designateDefault(User $user, NutritionTargetProfile $profile): bool
    {
        return $this->owns($user, $profile);
    }

    private function owns(User $user, NutritionTargetProfile $profile): bool
    {
        return (int) $user->getKey() === (int) $profile->user_id;
    }
}
