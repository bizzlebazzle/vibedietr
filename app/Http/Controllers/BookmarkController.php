<?php

namespace App\Http\Controllers;

use App\Domain\Bookmarks\BookmarkCreator;
use App\Models\Bookmark;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BookmarkController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        $this->user($request);
        $validated = $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return redirect()->route('recipes.index', ['view' => 'bookmarks', ...$validated]);
    }

    public function store(Request $request, int $recipe, BookmarkCreator $creator): RedirectResponse
    {
        $bookmark = $creator->create($recipe, $this->user($request));

        return redirect()
            ->route('recipes.show', $bookmark->recipe_id)
            ->with('status', 'Recipe bookmarked.');
    }

    public function destroy(Request $request, int $bookmark): RedirectResponse
    {
        $user = $this->user($request);
        $owned = $user->bookmarks()->find($bookmark);

        if ($owned instanceof Bookmark) {
            $this->authorize('delete', $owned);
            $owned->delete();
        }

        return redirect()
            ->back(fallback: route('bookmarks.index'))
            ->with('status', 'Bookmark removed.');
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
