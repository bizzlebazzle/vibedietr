<?php

namespace App\Http\Controllers;

use App\Domain\Recipes\ManagedRecipeTermSuggestionStatus;
use App\Domain\Recipes\PublicRecipe;
use App\Domain\Recipes\RecipeQuantityDisplay;
use App\Domain\Recipes\RecipeQuantityPresenter;
use App\Domain\Recipes\RecipeRemixAttributionPresenter;
use App\Domain\Recipes\RecipeRevisionManager;
use App\Domain\Recipes\RecipeVisibility;
use App\Domain\Recipes\RecipeVisibilityChanger;
use App\Models\ManagedRecipeTerm;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RecipeController extends Controller
{
    public function create(): View
    {
        $this->authorize('create', Recipe::class);

        return view('recipes.create');
    }

    public function show(
        Request $request,
        int $recipe,
        RecipeQuantityPresenter $quantityPresenter,
        RecipeRemixAttributionPresenter $attributionPresenter,
    ): View {
        $viewer = $request->user();
        $recipe = Recipe::query()
            ->visibleTo($viewer instanceof User ? $viewer : null)
            ->with('currentVersion')
            ->findOrFail($recipe);

        $this->authorize('view', $recipe);
        $isOwner = $viewer instanceof User && $viewer->getKey() === $recipe->user_id;

        $activeManagedTerms = collect();
        $pendingTagSuggestions = collect();
        if ($isOwner) {
            $activeManagedTerms = ManagedRecipeTerm::query()
                ->where('is_active', true)->orderBy('category')->orderBy('name')->get();
            $pendingTagSuggestions = $recipe->managedTermSuggestions()
                ->where('status', ManagedRecipeTermSuggestionStatus::Pending->value)
                ->with('term:id,category,name')->orderBy('created_at')->get();
        }

        $bookmark = $viewer instanceof User
            ? $viewer->bookmarks()->where('recipe_id', $recipe->getKey())->first()
            : null;

        if ($isOwner) {
            $recipe->load('activeRevision.baseVersion');
        }

        $previewingRevision = $request->query('preview') === 'draft';
        if ($previewingRevision && (! $isOwner || $recipe->activeRevision === null)) {
            abort(404);
        }

        $recipe->load('remixLineage');
        $remixAttribution = $recipe->remixLineage === null
            ? null
            : $attributionPresenter->present($recipe->remixLineage, $viewer instanceof User ? $viewer : null);
        $remixOperationId = (string) Str::ulid();

        if ($recipe->isFinalized() && ! $previewingRevision) {
            $publicRecipe = PublicRecipe::fromCurrentVersion($recipe);

            return view('recipes.show', [
                'recipe' => $recipe,
                'publicRecipe' => $publicRecipe,
                'quantityDisplay' => $this->quantityDisplay(
                    $request,
                    $quantityPresenter,
                    $publicRecipe->servings,
                    $publicRecipe->ingredients,
                ),
                'previewingRevision' => false,
                'bookmark' => $bookmark,
                'activeManagedTerms' => $activeManagedTerms,
                'pendingTagSuggestions' => $pendingTagSuggestions,
                'remixAttribution' => $remixAttribution,
                'remixOperationId' => $remixOperationId,
            ]);
        }

        $recipe->load(['ingredientLines', 'instructionSteps.section']);
        $ingredients = $recipe->ingredientLines->map(fn ($line): array => [
            'original_text' => $line->original_text,
            'quantity' => $line->quantity,
            'standard_unit' => $line->getRawOriginal('standard_unit'),
            'custom_unit' => $line->custom_unit,
            'generic_wording' => $line->generic_wording,
            'notes' => $line->notes,
        ])->values()->all();
        $savedServings = $recipe->getRawOriginal('servings');
        $originalServings = is_string($savedServings) ? $savedServings : null;

        return view('recipes.show', [
            'recipe' => $recipe,
            'publicRecipe' => null,
            'quantityDisplay' => $this->quantityDisplay(
                $request,
                $quantityPresenter,
                $originalServings,
                $ingredients,
            ),
            'previewingRevision' => $previewingRevision,
            'bookmark' => null,
            'activeManagedTerms' => $activeManagedTerms,
            'pendingTagSuggestions' => $pendingTagSuggestions,
            'remixAttribution' => $remixAttribution,
            'remixOperationId' => $remixOperationId,
        ]);
    }

    public function edit(Request $request, Recipe $recipe, RecipeRevisionManager $revisions): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        if ($recipe->isFinalized()) {
            $revisions->startOrResume((int) $recipe->getKey(), $user);
            $recipe->refresh();
        }

        $this->authorize('update', $recipe);

        return view('recipes.edit', compact('recipe'));
    }

    public function abandonRevision(
        Request $request,
        Recipe $recipe,
        RecipeRevisionManager $revisions,
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $updated = $revisions->abandon((int) $recipe->getKey(), $user);

        return redirect()
            ->route('recipes.show', $updated)
            ->with('status', 'Draft revision abandoned. The finalized version was not changed.');
    }

    public function updateVisibility(
        Request $request,
        Recipe $recipe,
        RecipeVisibilityChanger $changer,
    ): RedirectResponse {
        $this->authorize('changeVisibility', $recipe);

        $validated = $request->validate([
            'visibility' => ['required', Rule::enum(RecipeVisibility::class)],
        ]);
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $updated = $changer->change(
            (int) $recipe->getKey(),
            RecipeVisibility::from($validated['visibility']),
            $user,
        );

        return redirect()
            ->route('recipes.show', $updated)
            ->with('status', 'Recipe visibility changed to '.$updated->getRawOriginal('visibility').'.');
    }

    /**
     * @param  list<array{original_text: string, quantity: string|null, standard_unit: string|null, custom_unit: string|null, generic_wording: string|null, notes: string|null}>  $ingredients
     */
    private function quantityDisplay(
        Request $request,
        RecipeQuantityPresenter $presenter,
        ?string $originalServings,
        array $ingredients,
    ): RecipeQuantityDisplay {
        $requested = $request->query('servings');
        $requestError = null;

        if ($requested === null || $requested === '') {
            $requested = $originalServings;
        } else {
            $validator = Validator::make(
                ['servings' => $requested],
                ['servings' => ['required', 'string', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99']],
                [
                    'servings.decimal' => 'Enter a valid serving count with no more than two decimal places.',
                    'servings.gt' => 'Requested servings must be greater than zero.',
                    'servings.max' => 'Requested servings are too large.',
                    'servings.numeric' => 'Enter a valid serving count with no more than two decimal places.',
                    'servings.string' => 'Enter a valid numeric serving count.',
                ],
            );

            if ($validator->fails()) {
                $requestError = $validator->errors()->first('servings');
                $requested = $originalServings;
            } else {
                $requested = (string) $validator->validated()['servings'];
            }
        }

        return $presenter->present($originalServings, $requested, $ingredients, $requestError);
    }
}
