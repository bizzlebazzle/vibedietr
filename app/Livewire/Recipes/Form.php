<?php

namespace App\Livewire\Recipes;

use App\Domain\Measurements\MeasurementUnitRegistry;
use App\Domain\Measurements\StandardUnit;
use App\Domain\Recipes\RecipeDraftEditor;
use App\Domain\Recipes\RecipeDraftFingerprint;
use App\Domain\Recipes\RecipeFinalizer;
use App\Domain\Recipes\RecipeVisibility;
use App\Domain\Recipes\StaleRecipeDraft;
use App\Domain\Shared\Decimal;
use App\Models\Recipe;
use App\Models\User;
use App\Rules\ValidMeasurementUnit;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

class Form extends Component
{
    public ?int $recipeId = null;

    #[Locked]
    public string $baselineFingerprint = '';

    public string $title = '';

    public $servings = null;

    public string $visibility = RecipeVisibility::Public->value;

    /** @var list<array{key: string, id: int|null, original_text: string, quantity: mixed, unit: string, generic_wording: string, notes: string}> */
    public array $ingredients = [];

    /** @var list<array{key: string, id: int|null, name: string}> */
    public array $sections = [];

    /** @var list<array{key: string, id: int|null, text: string, section_key: string|null}> */
    public array $steps = [];

    public bool $unsaved = false;

    private bool $loading = false;

    public function mount(?Recipe $recipe = null): void
    {
        if ($recipe?->exists) {
            $this->authorize('update', $recipe);
            $this->loadRecipe($recipe);

            return;
        }

        $this->authorize('create', Recipe::class);
    }

    public function updated(string $property): void
    {
        if (! $this->loading && $property !== 'unsaved') {
            $this->unsaved = true;
        }
    }

    /** @return array<string, array<int, mixed>> */
    protected function rules(): array
    {
        $sectionKeys = collect($this->sections)->pluck('key')->all();

        return [
            'title' => ['required', 'string', 'max:255'],
            'servings' => ['nullable', 'decimal:0,2', 'gt:0'],
            'visibility' => ['required', Rule::enum(RecipeVisibility::class)],
            'ingredients' => ['array'],
            'ingredients.*.key' => ['required', 'string'],
            'ingredients.*.id' => ['nullable', 'integer'],
            'ingredients.*.original_text' => ['required', 'string', 'max:10000', $this->nonBlank('The original ingredient line is required.')],
            'ingredients.*.quantity' => ['nullable', $this->validQuantity()],
            'ingredients.*.unit' => ['nullable', 'string', new ValidMeasurementUnit],
            'ingredients.*.generic_wording' => ['nullable', 'string', 'max:255'],
            'ingredients.*.notes' => ['nullable', 'string', 'max:2000'],
            'sections' => ['array'],
            'sections.*.key' => ['required', 'string'],
            'sections.*.id' => ['nullable', 'integer'],
            'sections.*.name' => ['required', 'string', 'max:255', $this->nonBlank('The section name is required.')],
            'steps' => ['array'],
            'steps.*.key' => ['required', 'string'],
            'steps.*.id' => ['nullable', 'integer'],
            'steps.*.text' => ['required', 'string', 'max:10000', $this->nonBlank('The instruction step is required.')],
            'steps.*.section_key' => ['nullable', 'string', Rule::in($sectionKeys)],
        ];
    }

    public function addIngredient(): void
    {
        $this->ingredients[] = ['key' => $this->newKey('ingredient'), 'id' => null, 'original_text' => '', 'quantity' => null, 'unit' => '', 'generic_wording' => '', 'notes' => ''];
        $this->unsaved = true;
    }

    public function removeIngredient(int $index): void
    {
        $this->removeAt($this->ingredients, $index);
    }

    public function moveIngredientUp(int $index): void
    {
        $this->move($this->ingredients, $index, -1);
    }

    public function moveIngredientDown(int $index): void
    {
        $this->move($this->ingredients, $index, 1);
    }

    public function addSection(): void
    {
        $this->sections[] = ['key' => $this->newKey('section'), 'id' => null, 'name' => ''];
        $this->unsaved = true;
    }

    public function removeSection(int $index): void
    {
        if (! array_key_exists($index, $this->sections)) {
            abort(404);
        }
        $key = $this->sections[$index]['key'];
        $this->removeAt($this->sections, $index);

        foreach ($this->steps as &$step) {
            if ($step['section_key'] === $key) {
                $step['section_key'] = null;
            }
        }
        unset($step);
    }

    public function moveSectionUp(int $index): void
    {
        $this->move($this->sections, $index, -1);
    }

    public function moveSectionDown(int $index): void
    {
        $this->move($this->sections, $index, 1);
    }

    public function addStep(): void
    {
        $this->steps[] = ['key' => $this->newKey('step'), 'id' => null, 'text' => '', 'section_key' => null];
        $this->unsaved = true;
    }

    public function removeStep(int $index): void
    {
        $this->removeAt($this->steps, $index);
    }

    public function moveStepUp(int $index): void
    {
        $this->move($this->steps, $index, -1);
    }

    public function moveStepDown(int $index): void
    {
        $this->move($this->steps, $index, 1);
    }

    public function save(RecipeDraftEditor $editor, RecipeDraftFingerprint $fingerprint): void
    {
        $this->unsaved = true;
        $enteredTitle = $this->title;
        $this->title = trim($this->title);

        try {
            $validated = $this->validate();
            $this->assertUniqueKeys();
        } catch (ValidationException $exception) {
            $this->title = $enteredTitle;

            throw $exception;
        }

        if ($this->recipeId === null) {
            $this->createRecipe($validated);

            return;
        }

        try {
            $recipe = $editor->save(
                $this->recipeId,
                $this->baselineFingerprint,
                ['title' => $validated['title'], 'servings' => $validated['servings'], 'visibility' => $validated['visibility']],
                $validated['ingredients'],
                $validated['sections'],
                $validated['steps'],
            );
        } catch (StaleRecipeDraft $exception) {
            $this->addError('conflict', $exception->getMessage());

            return;
        } catch (AuthorizationException|ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError(
                'save',
                'The draft could not be saved. Nothing was changed; please try again.',
            );

            return;
        }

        $this->loadRecipe($recipe->fresh(), $fingerprint);
        session()->flash('status', 'Recipe draft saved.');
    }

    public function finalize(RecipeFinalizer $finalizer): void
    {
        if ($this->recipeId === null) {
            $this->addError('finalize', 'Create the draft before finalizing it.');

            return;
        }

        $user = auth()->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $this->unsaved = true;
        $enteredTitle = $this->title;
        $this->title = trim($this->title);

        try {
            $rules = $this->rules();
            $rules['servings'] = ['required', 'decimal:0,2', 'gt:0'];
            $rules['ingredients'] = ['required', 'array', 'min:1'];
            $rules['steps'] = ['required', 'array', 'min:1'];
            $validated = $this->validate($rules);
            $this->assertUniqueKeys();
        } catch (ValidationException $exception) {
            $this->title = $enteredTitle;

            throw $exception;
        }

        try {
            $version = $finalizer->finalize(
                $this->recipeId,
                $this->baselineFingerprint,
                [
                    'title' => $validated['title'],
                    'servings' => $validated['servings'],
                    'visibility' => $validated['visibility'],
                ],
                $validated['ingredients'],
                $validated['sections'],
                $validated['steps'],
                $user,
            );
        } catch (StaleRecipeDraft $exception) {
            $this->addError('conflict', $exception->getMessage());

            return;
        } catch (AuthorizationException|ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError(
                'finalize',
                'The recipe could not be finalized. The draft was not changed; please try again.',
            );

            return;
        }

        session()->flash('status', 'Recipe finalized as '.$version->getRawOriginal('visibility').'.');
        $this->redirectRoute('recipes.show', ['recipe' => $this->recipeId], navigate: true);
    }

    public function render()
    {
        return view('livewire.recipes.form', [
            'visibilityOptions' => RecipeVisibility::cases(),
            'unitGroups' => MeasurementUnitRegistry::formGroups(),
            'customUnits' => MeasurementUnitRegistry::suggestedCustomUnits(),
        ]);
    }

    /** @param array<string, mixed> $validated */
    private function createRecipe(array $validated): void
    {
        $this->authorize('create', Recipe::class);
        $user = auth()->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $recipe = new Recipe(['title' => $validated['title'], 'servings' => $validated['servings'], 'visibility' => $validated['visibility']]);
        $recipe->owner()->associate($user);
        $recipe->save();
        $this->recipeId = $recipe->getKey();
        $this->unsaved = false;
        session()->flash('status', 'Recipe draft created.');
        $this->redirectRoute('recipes.show', ['recipe' => $recipe], navigate: true);
    }

    private function loadRecipe(Recipe $recipe, ?RecipeDraftFingerprint $fingerprint = null): void
    {
        $this->loading = true;
        $recipe->load(['ingredientLines', 'instructionSections', 'instructionSteps']);
        $sectionKeysById = $recipe->instructionSections->mapWithKeys(fn ($section): array => [$section->getKey() => 'section-'.$section->getKey()]);
        $this->recipeId = $recipe->getKey();
        $this->title = $recipe->title;
        $this->servings = $recipe->servings;
        $this->visibility = (string) $recipe->getRawOriginal('visibility');
        $this->ingredients = $recipe->ingredientLines->map(fn ($line): array => [
            'key' => 'ingredient-'.$line->getKey(), 'id' => $line->getKey(), 'original_text' => $line->original_text,
            'quantity' => $line->quantity,
            'unit' => $line->standard_unit instanceof StandardUnit ? MeasurementUnitRegistry::definition($line->standard_unit)->symbol : ($line->custom_unit ?? ''),
            'generic_wording' => $line->generic_wording ?? '', 'notes' => $line->notes ?? '',
        ])->values()->all();
        $this->sections = $recipe->instructionSections->map(fn ($section): array => ['key' => $sectionKeysById->get($section->getKey()), 'id' => $section->getKey(), 'name' => $section->name])->values()->all();
        $this->steps = $recipe->instructionSteps->map(fn ($step): array => [
            'key' => 'step-'.$step->getKey(), 'id' => $step->getKey(), 'text' => $step->text,
            'section_key' => $step->section_id === null ? null : $sectionKeysById->get($step->section_id),
        ])->values()->all();
        $this->baselineFingerprint = ($fingerprint ?? app(RecipeDraftFingerprint::class))->forRecipe($recipe);
        $this->unsaved = false;
        $this->resetValidation();
        $this->loading = false;
    }

    private function nonBlank(string $message): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($message): void {
            if (! is_string($value) || trim($value) === '') {
                $fail($message);
            }
        };
    }

    private function validQuantity(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }
            if (! is_string($value) && ! is_int($value)) {
                $fail('The quantity must be a non-negative decimal.');

                return;
            }
            try {
                Decimal::forStorage(Decimal::parse($value));
            } catch (InvalidArgumentException) {
                $fail('The quantity must be a non-negative decimal within the supported range.');
            }
        };
    }

    private function assertUniqueKeys(): void
    {
        foreach (['ingredients', 'sections', 'steps'] as $property) {
            $keys = collect($this->{$property})->pluck('key')->all();
            if (count($keys) !== count(array_unique($keys))) {
                throw ValidationException::withMessages([$property => 'Editor row identifiers must be unique.']);
            }
        }
    }

    /** @param array<int, mixed> $items */
    private function removeAt(array &$items, int $index): void
    {
        if (! array_key_exists($index, $items)) {
            abort(404);
        }
        array_splice($items, $index, 1);
        $this->unsaved = true;
    }

    /** @param array<int, mixed> $items */
    private function move(array &$items, int $index, int $offset): void
    {
        if (! array_key_exists($index, $items)) {
            abort(404);
        }
        $target = $index + $offset;
        if (! array_key_exists($target, $items)) {
            return;
        }
        [$items[$index], $items[$target]] = [$items[$target], $items[$index]];
        $this->unsaved = true;
    }

    private function newKey(string $prefix): string
    {
        return $prefix.'-'.Str::uuid();
    }
}
