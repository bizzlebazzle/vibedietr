<?php

namespace App\Http\Controllers;

use App\Domain\Bookmarks\BookmarkListing;
use App\Domain\Recipes\PrivateOrganizationMemberships;
use App\Http\Controllers\Concerns\ValidatesPrivateOrganizationName;
use App\Models\RecipeCollection;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RecipeCollectionController extends Controller
{
    use ValidatesPrivateOrganizationName;

    public function index(Request $request): View
    {
        $owner = $this->owner($request);
        $this->authorize('viewAny', RecipeCollection::class);
        $collections = $owner->recipeCollections()->orderBy('name')->orderBy('id')->get();

        return view('recipe-collections.index', compact('collections'));
    }

    public function store(Request $request): RedirectResponse
    {
        $owner = $this->owner($request);
        $this->authorize('create', RecipeCollection::class);
        $name = $this->validatedName($request, $owner, 'recipe_collections');
        $collection = RecipeCollection::unguarded(
            fn (): RecipeCollection => $owner->recipeCollections()->create(['name' => $name]),
        );

        return redirect()->route('recipe-collections.show', $collection)->with('status', 'Collection created.');
    }

    public function show(Request $request, int $collection, BookmarkListing $bookmarks): View
    {
        $owner = $this->owner($request);
        $collection = $this->collection($owner, $collection);
        $this->authorize('view', $collection);
        $recipes = $collection->recipes()->where('recipes.user_id', $owner->getKey())->get();
        $bookmarkModels = $collection->bookmarks()->where('bookmarks.user_id', $owner->getKey())->get();
        $bookmarkItems = $bookmarks->project($owner, $bookmarkModels);
        $ownedRecipes = $owner->recipes()->orderBy('title')->orderBy('id')->get();
        $ownedBookmarkModels = $owner->bookmarks()->orderByDesc('created_at')->orderByDesc('id')->get();
        $ownedBookmarks = $bookmarks->project($owner, $ownedBookmarkModels);

        return view('recipe-collections.show', compact(
            'collection', 'recipes', 'bookmarkItems', 'ownedRecipes', 'ownedBookmarks',
        ));
    }

    public function update(Request $request, int $collection): RedirectResponse
    {
        $owner = $this->owner($request);
        $collection = $this->collection($owner, $collection);
        $this->authorize('update', $collection);
        $collection->forceFill([
            'name' => $this->validatedName($request, $owner, 'recipe_collections', $collection->id),
        ])->save();

        return redirect()->route('recipe-collections.show', $collection)->with('status', 'Collection renamed.');
    }

    public function destroy(Request $request, int $collection): RedirectResponse
    {
        $owner = $this->owner($request);
        $collection = $this->collection($owner, $collection);
        $this->authorize('delete', $collection);
        $collection->delete();

        return redirect()->route('recipe-collections.index')->with('status', 'Collection deleted.');
    }

    public function storeRecipe(Request $request, int $collection, PrivateOrganizationMemberships $memberships): RedirectResponse
    {
        $validated = $request->validate(['recipe_id' => ['required', 'integer'], 'user_id' => ['prohibited']]);
        $memberships->addRecipeToCollection($collection, (int) $validated['recipe_id'], $this->owner($request));

        return back()->with('status', 'Owned recipe added.');
    }

    public function destroyRecipe(Request $request, int $collection, int $recipe, PrivateOrganizationMemberships $memberships): RedirectResponse
    {
        $memberships->removeRecipeFromCollection($collection, $recipe, $this->owner($request));

        return back()->with('status', 'Recipe removed from collection.');
    }

    public function storeBookmark(Request $request, int $collection, PrivateOrganizationMemberships $memberships): RedirectResponse
    {
        $validated = $request->validate(['bookmark_id' => ['required', 'integer'], 'user_id' => ['prohibited']]);
        $memberships->addBookmarkToCollection($collection, (int) $validated['bookmark_id'], $this->owner($request));

        return back()->with('status', 'Bookmark added.');
    }

    public function destroyBookmark(Request $request, int $collection, int $bookmark, PrivateOrganizationMemberships $memberships): RedirectResponse
    {
        $memberships->removeBookmarkFromCollection($collection, $bookmark, $this->owner($request));

        return back()->with('status', 'Bookmark removed from collection.');
    }

    private function owner(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    private function collection(User $owner, int $id): RecipeCollection
    {
        return $owner->recipeCollections()->findOrFail($id);
    }
}
