<?php

namespace App\Http\Controllers;

use App\Domain\Recipes\PublicRecipeDiscovery;
use App\Http\Requests\PublicRecipeDiscoveryRequest;
use Illuminate\Contracts\View\View;

class RecipeDiscoveryController extends Controller
{
    public function __invoke(
        PublicRecipeDiscoveryRequest $request,
        PublicRecipeDiscovery $discovery,
    ): View {
        $search = $request->search();
        $recipes = $discovery->paginate($search);

        if ($search !== '') {
            $recipes->appends(['q' => $search]);
        }

        return view('recipes.index', compact('recipes', 'search'));
    }
}
