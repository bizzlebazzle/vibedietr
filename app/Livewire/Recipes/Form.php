<?php

namespace App\Livewire\Recipes;

use App\Domain\Recipes\RecipeVisibility;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Form extends Component
{
    public ?int $recipeId = null;

    public string $title = '';

    public $servings = null;

    public string $visibility = RecipeVisibility::Public->value;

    public function mount(?Recipe $recipe = null): void
    {
        if ($recipe?->exists) {
            $this->authorize('update', $recipe);
            $this->recipeId = $recipe->getKey();
            $this->title = $recipe->title;
            $this->servings = $recipe->servings;
            $this->visibility = (string) $recipe->getRawOriginal('visibility');

            return;
        }

        $this->authorize('create', Recipe::class);
    }

    /** @return array<string, array<int, mixed>> */
    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'servings' => ['nullable', 'decimal:0,2', 'gt:0'],
            'visibility' => ['required', Rule::enum(RecipeVisibility::class)],
        ];
    }

    public function save(): void
    {
        $this->title = trim($this->title);
        $validated = $this->validate();

        if ($this->recipeId === null) {
            $this->authorize('create', Recipe::class);
            $user = auth()->user();

            if (! $user instanceof User) {
                abort(403);
            }

            $recipe = new Recipe($validated);
            $recipe->owner()->associate($user);
            $recipe->save();
            $this->recipeId = $recipe->getKey();
            session()->flash('status', 'Recipe draft created.');
        } else {
            $recipe = Recipe::query()->findOrFail($this->recipeId);
            $this->authorize('update', $recipe);
            $recipe->update($validated);
            session()->flash('status', 'Recipe draft updated.');
        }

        $this->redirectRoute('recipes.show', ['recipe' => $recipe], navigate: true);
    }

    public function render()
    {
        return view('livewire.recipes.form', [
            'visibilityOptions' => RecipeVisibility::cases(),
        ]);
    }
}
