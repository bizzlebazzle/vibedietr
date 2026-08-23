<?php

namespace App\Http\Controllers;

use App\Domain\Bookmarks\BookmarkListing;
use App\Domain\Recipes\PrivateOrganizationMemberships;
use App\Http\Controllers\Concerns\ValidatesPrivateOrganizationName;
use App\Models\PrivateRecipeTag;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PrivateRecipeTagController extends Controller
{
    use ValidatesPrivateOrganizationName;

    public function index(Request $request): View
    {
        $owner = $this->owner($request);
        $this->authorize('viewAny', PrivateRecipeTag::class);
        $tags = $owner->privateRecipeTags()->orderBy('name')->orderBy('id')->get();

        return view('private-recipe-tags.index', compact('tags'));
    }

    public function store(Request $request): RedirectResponse
    {
        $owner = $this->owner($request);
        $this->authorize('create', PrivateRecipeTag::class);
        $name = $this->validatedName($request, $owner, 'private_recipe_tags');
        $tag = PrivateRecipeTag::unguarded(
            fn (): PrivateRecipeTag => $owner->privateRecipeTags()->create(['name' => $name]),
        );

        return redirect()->route('private-recipe-tags.show', $tag)->with('status', 'Private tag created.');
    }

    public function show(Request $request, int $tag, BookmarkListing $bookmarks): View
    {
        $owner = $this->owner($request);
        $tag = $this->tag($owner, $tag);
        $this->authorize('view', $tag);
        $recipes = $tag->recipes()->where('recipes.user_id', $owner->getKey())->get();
        $bookmarkModels = $tag->bookmarks()->where('bookmarks.user_id', $owner->getKey())->get();
        $bookmarkItems = $bookmarks->project($owner, $bookmarkModels);
        $ownedRecipes = $owner->recipes()->orderBy('title')->orderBy('id')->get();
        $ownedBookmarkModels = $owner->bookmarks()->orderByDesc('created_at')->orderByDesc('id')->get();
        $ownedBookmarks = $bookmarks->project($owner, $ownedBookmarkModels);

        return view('private-recipe-tags.show', compact(
            'tag', 'recipes', 'bookmarkItems', 'ownedRecipes', 'ownedBookmarks',
        ));
    }

    public function update(Request $request, int $tag): RedirectResponse
    {
        $owner = $this->owner($request);
        $tag = $this->tag($owner, $tag);
        $this->authorize('update', $tag);
        $tag->forceFill([
            'name' => $this->validatedName($request, $owner, 'private_recipe_tags', $tag->id),
        ])->save();

        return redirect()->route('private-recipe-tags.show', $tag)->with('status', 'Private tag renamed.');
    }

    public function destroy(Request $request, int $tag): RedirectResponse
    {
        $owner = $this->owner($request);
        $tag = $this->tag($owner, $tag);
        $this->authorize('delete', $tag);
        $tag->delete();

        return redirect()->route('private-recipe-tags.index')->with('status', 'Private tag deleted.');
    }

    public function storeRecipe(Request $request, int $tag, PrivateOrganizationMemberships $memberships): RedirectResponse
    {
        $validated = $request->validate(['recipe_id' => ['required', 'integer'], 'user_id' => ['prohibited']]);
        $memberships->addRecipeToTag($tag, (int) $validated['recipe_id'], $this->owner($request));

        return back()->with('status', 'Private tag applied to owned recipe.');
    }

    public function destroyRecipe(Request $request, int $tag, int $recipe, PrivateOrganizationMemberships $memberships): RedirectResponse
    {
        $memberships->removeRecipeFromTag($tag, $recipe, $this->owner($request));

        return back()->with('status', 'Private tag removed from recipe.');
    }

    public function storeBookmark(Request $request, int $tag, PrivateOrganizationMemberships $memberships): RedirectResponse
    {
        $validated = $request->validate(['bookmark_id' => ['required', 'integer'], 'user_id' => ['prohibited']]);
        $memberships->addBookmarkToTag($tag, (int) $validated['bookmark_id'], $this->owner($request));

        return back()->with('status', 'Private tag applied to bookmark.');
    }

    public function destroyBookmark(Request $request, int $tag, int $bookmark, PrivateOrganizationMemberships $memberships): RedirectResponse
    {
        $memberships->removeBookmarkFromTag($tag, $bookmark, $this->owner($request));

        return back()->with('status', 'Private tag removed from bookmark.');
    }

    private function owner(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    private function tag(User $owner, int $id): PrivateRecipeTag
    {
        return $owner->privateRecipeTags()->findOrFail($id);
    }
}
