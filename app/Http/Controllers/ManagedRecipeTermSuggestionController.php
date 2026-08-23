<?php

namespace App\Http\Controllers;

use App\Domain\Recipes\ManagedRecipeTermSuggestionStatus;
use App\Domain\Recipes\PublicRecipeMetadataManager;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ManagedRecipeTermSuggestionController extends Controller
{
    public function store(Request $request, PublicRecipeMetadataManager $manager): RedirectResponse
    {
        $this->authorize('access-admin');
        $validated = $request->validate([
            'recipe_id' => ['required', 'integer', 'min:1'],
            'managed_term_id' => ['required', 'string', 'max:26'],
        ]);
        $manager->suggest((int) $validated['recipe_id'], $validated['managed_term_id'], $this->user($request));

        return back()->with('status', 'Classification suggestion sent for creator review.');
    }

    public function accept(Request $request, string $suggestion, PublicRecipeMetadataManager $manager): RedirectResponse
    {
        $manager->review($suggestion, $this->user($request), ManagedRecipeTermSuggestionStatus::Accepted);

        return back()->with('status', 'Suggestion accepted.');
    }

    public function reject(Request $request, string $suggestion, PublicRecipeMetadataManager $manager): RedirectResponse
    {
        $manager->review($suggestion, $this->user($request), ManagedRecipeTermSuggestionStatus::Rejected);

        return back()->with('status', 'Suggestion rejected.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }
}
