<?php

namespace App\Domain\Profiles;

use App\Models\PublicProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PublicProfileSettingsUpdater
{
    /**
     * @param  array{attribution_name: string|null, profile_enabled: bool, show_public_recipes: bool, show_public_remixes: bool}  $settings
     */
    public function update(User $owner, array $settings): PublicProfile
    {
        return DB::transaction(function () use ($owner, $settings): PublicProfile {
            $profile = PublicProfile::query()
                ->where('user_id', $owner->getKey())
                ->lockForUpdate()
                ->first() ?? new PublicProfile;

            if (! $profile->exists) {
                $profile->user()->associate($owner);
            }

            $profile->fill([
                'attribution_name' => $settings['attribution_name'] === null
                    ? null
                    : trim($settings['attribution_name']),
                'profile_enabled' => $settings['profile_enabled'],
                'show_public_recipes' => $settings['show_public_recipes'],
                'show_public_remixes' => $settings['show_public_remixes'],
            ])->save();

            return $profile;
        }, 3);
    }
}
