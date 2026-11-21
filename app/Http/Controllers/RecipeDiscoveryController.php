<?php

namespace App\Http\Controllers;

use App\Domain\Bookmarks\BookmarkListing;
use App\Domain\Recipes\PublicRecipeDiscovery;
use App\Http\Requests\PublicRecipeDiscoveryRequest;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class RecipeDiscoveryController extends Controller
{
    public function __invoke(
        PublicRecipeDiscoveryRequest $request,
        PublicRecipeDiscovery $discovery,
        BookmarkListing $bookmarks,
    ): View|RedirectResponse {
        $view = $request->validated('view') ?? 'discover';
        $user = $request->user();
        if ($view !== 'discover' && ! $user instanceof User) {
            return redirect()->guest(route('login'));
        }
        if ($view === 'bookmarks') {
            return view('recipes.index', [
                'view' => $view,
                'bookmarks' => $bookmarks->paginate($user)->appends(['view' => $view]),
            ]);
        }
        if ($view === 'mine' || $view === 'drafts') {
            $query = Recipe::query()->where('user_id', $user->getKey())->with(['currentVersion', 'activeRevision']);
            $view === 'mine' ? $query->finalized() : $query->withDraft();
            $recipes = $query->orderByDesc('updated_at')->orderByDesc('id')
                ->paginate(12)->appends(['view' => $view]);

            return view('recipes.index', compact('recipes', 'view'));
        }
        $search = $request->search();
        $recipes = $discovery->paginate($search);

        if ($search !== '') {
            $recipes->appends(['q' => $search]);
        }

        return view('recipes.index', compact('recipes', 'search', 'view'));
    }
}
