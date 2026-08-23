<?php

namespace App\Http\Controllers;

use App\Domain\Recipes\PublicRecipeMetadataManager;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PublicRecipeMetadataController extends Controller
{
    public function storeTag(Request $request, int $recipe, PublicRecipeMetadataManager $manager): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100', 'regex:/\S/u']]);
        $manager->addFreeFormTag($recipe, $this->user($request), $validated['name']);

        return back()->with('status', 'Public tag added.');
    }

    public function destroyTag(Request $request, int $recipe, int $tag, PublicRecipeMetadataManager $manager): RedirectResponse
    {
        $manager->removeFreeFormTag($recipe, $tag, $this->user($request));

        return back()->with('status', 'Public tag removed.');
    }

    public function storeClassification(Request $request, int $recipe, PublicRecipeMetadataManager $manager): RedirectResponse
    {
        $validated = $request->validate(['managed_term_id' => ['required', 'string', 'max:26']]);
        $manager->attachManagedTerm($recipe, $validated['managed_term_id'], $this->user($request));

        return back()->with('status', 'Classification added.');
    }

    public function destroyClassification(Request $request, int $recipe, string $term, PublicRecipeMetadataManager $manager): RedirectResponse
    {
        $manager->removeManagedTerm($recipe, $term, $this->user($request));

        return back()->with('status', 'Classification removed.');
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
