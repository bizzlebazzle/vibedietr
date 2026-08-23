<?php

namespace App\Http\Controllers;

use App\Domain\Recipes\ManagedRecipeTermCategory;
use App\Domain\Recipes\ManagedRecipeVocabularyAudit;
use App\Domain\Recipes\RecipeTagName;
use App\Models\ManagedRecipeTerm;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ManagedRecipeTermController extends Controller
{
    public function index(): View
    {
        $this->authorize('access-admin');
        $this->authorize('viewAny', ManagedRecipeTerm::class);
        $terms = ManagedRecipeTerm::query()->orderBy('category')->orderBy('name')->orderBy('id')->get();

        return view('admin.managed-recipe-terms.index', [
            'terms' => $terms,
            'categories' => ManagedRecipeTermCategory::cases(),
        ]);
    }

    public function store(Request $request, ManagedRecipeVocabularyAudit $audit): RedirectResponse
    {
        $this->authorize('access-admin');
        $this->authorize('create', ManagedRecipeTerm::class);
        $validated = $request->validate([
            'category' => ['required', Rule::enum(ManagedRecipeTermCategory::class)],
            'name' => ['required', 'string', 'max:100', 'regex:/\S/u'],
        ]);
        $administrator = $this->administrator($request);

        DB::transaction(function () use ($validated, $administrator, $audit): void {
            $normalized = RecipeTagName::normalized($validated['name']);
            if (ManagedRecipeTerm::query()->where('category', $validated['category'])->where('normalized_name', $normalized)->exists()) {
                abort(422, 'That managed term already exists in this category.');
            }

            $term = new ManagedRecipeTerm;
            $term->forceFill([
                'category' => $validated['category'],
                'name' => RecipeTagName::display($validated['name']),
                'is_active' => true,
            ])->save();
            $audit->vocabularyChanged($term, $administrator, 'created');
        });

        return back()->with('status', 'Managed term created.');
    }

    public function update(
        Request $request,
        ManagedRecipeTerm $term,
        ManagedRecipeVocabularyAudit $audit,
    ): RedirectResponse {
        $this->authorize('access-admin');
        $this->authorize('update', $term);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'regex:/\S/u'],
            'is_active' => ['required', 'boolean'],
        ]);
        $administrator = $this->administrator($request);

        DB::transaction(function () use ($term, $validated, $administrator, $audit): void {
            $term = ManagedRecipeTerm::query()->lockForUpdate()->findOrFail($term->getKey());
            $normalized = RecipeTagName::normalized($validated['name']);
            if (ManagedRecipeTerm::query()->where('category', $term->category->value)
                ->where('normalized_name', $normalized)->whereKeyNot($term->getKey())->exists()) {
                abort(422, 'That managed term already exists in this category.');
            }

            $renamed = $term->name !== RecipeTagName::display($validated['name']);
            $activeChanged = $term->is_active !== (bool) $validated['is_active'];
            $term->forceFill(['name' => RecipeTagName::display($validated['name']), 'is_active' => (bool) $validated['is_active']])->save();

            if ($renamed) {
                $audit->vocabularyChanged($term, $administrator, 'renamed');
            }
            if ($activeChanged) {
                $audit->vocabularyChanged($term, $administrator, $term->is_active ? 'activated' : 'deactivated');
            }
        });

        return back()->with('status', 'Managed term updated.');
    }

    private function administrator(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }
}
