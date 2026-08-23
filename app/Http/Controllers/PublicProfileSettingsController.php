<?php

namespace App\Http\Controllers;

use App\Domain\Profiles\PublicProfileSettingsUpdater;
use App\Http\Requests\UpdatePublicProfileRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class PublicProfileSettingsController extends Controller
{
    public function update(
        UpdatePublicProfileRequest $request,
        PublicProfileSettingsUpdater $updater,
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        /** @var array{attribution_name: string|null, profile_enabled: bool, show_public_recipes: bool, show_public_remixes: bool} $settings */
        $settings = $request->safe()->only([
            'attribution_name',
            'profile_enabled',
            'show_public_recipes',
            'show_public_remixes',
        ]);
        $updater->update($user, $settings);

        return redirect()->route('profile')->with('status', 'Public attribution settings saved.');
    }
}
