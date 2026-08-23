<?php

namespace App\Http\Controllers;

use App\Domain\Bookmarks\BookmarkCreator;
use App\Domain\Bookmarks\BookmarkListing;
use App\Models\Bookmark;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BookmarkController extends Controller
{
    public function index(Request $request, BookmarkListing $listing): View
    {
        $user = $this->user($request);

        return view('bookmarks.index', ['bookmarks' => $listing->paginate($user)]);
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
