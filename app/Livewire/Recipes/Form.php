<?php

namespace App\Livewire\Recipes;

use App\Domain\Catalogue\CatalogueItemStatus;
use App\Domain\Catalogue\CatalogueReadQuery;
use App\Domain\Measurements\MeasurementUnitRegistry;
use App\Domain\Measurements\StandardUnit;
use App\Domain\Recipes\RecipeDraftEditor;
use App\Domain\Recipes\RecipeDraftFingerprint;
use App\Domain\Recipes\RecipeFinalizer;
use App\Domain\Recipes\RecipeIngredientMatchManager;
use App\Domain\Recipes\RecipeRevisionPublisher;
use App\Domain\Recipes\RecipeVisibility;
use App\Domain\Recipes\StaleRecipeDraft;
use App\Domain\Recipes\StaleRecipeRevision;
use App\Domain\Shared\Decimal;
use App\Models\Recipe;
use App\Models\RecipeIngredientLine;
use App\Models\RecipeIngredientLineMatch;
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

    #[Locked]
    public bool $isRevision = false;

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

    /** @var array<int, string> */
    public array $catalogueSearches = [];

    /** @var array<int, list<array{item_id: int, version_id: string, name: string, barcode: string|null, pending: bool}>> */
    public array $catalogueResults = [];

    /** @var array<int, array{page: int, last_page: int, total: int}> */
    public array $catalogueResultPages = [];

    /** @var array<int, array{item_id: int, version_id: string, name: string, review_state: string}> */
    public array $catalogueMatches = [];

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
        if (! $this->loading && $property !== 'unsaved' && ! str_starts_with($property, 'catalogueSearches.')) {
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

    public function finalize(RecipeFinalizer $finalizer, RecipeRevisionPublisher $revisionPublisher): void
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
            $recipe = Recipe::query()->findOrFail($this->recipeId);
            $metadata = [
                'title' => $validated['title'],
                'servings' => $validated['servings'],
                'visibility' => $validated['visibility'],
            ];

            $version = $recipe->isFinalized()
                ? $revisionPublisher->publish(
                    $this->recipeId,
                    $this->baselineFingerprint,
                    $metadata,
                    $validated['ingredients'],
                    $validated['sections'],
                    $validated['steps'],
                    $user,
                )
                : $finalizer->finalize(
                    $this->recipeId,
                    $this->baselineFingerprint,
                    $metadata,
                    $validated['ingredients'],
                    $validated['sections'],
                    $validated['steps'],
                    $user,
                );
        } catch (StaleRecipeDraft|StaleRecipeRevision $exception) {
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

        session()->flash('status', $this->isRevision
            ? 'Draft revision published as version '.$version->version_number.'.'
            : 'Recipe finalized as '.$version->getRawOriginal('visibility').'.');
        $this->redirectRoute('recipes.show', ['recipe' => $this->recipeId], navigate: true);
    }

    public function searchCatalogue(int $index, CatalogueReadQuery $catalogue): void
    {
        $this->loadCatalogueResults($index, 1, $catalogue);
    }

    public function changeCataloguePage(int $index, int $page, CatalogueReadQuery $catalogue): void
    {
        $this->loadCatalogueResults($index, $page, $catalogue);
    }

    public function selectCatalogueMatch(
        int $index,
        int $catalogueItemId,
        string $catalogueVersionId,
        RecipeIngredientMatchManager $matches,
        RecipeDraftFingerprint $fingerprint,
    ): void {
        $user = auth()->user();
        if (! $user instanceof User || $this->recipeId === null) {
            abort(403);
        }

        $line = $this->persistedIngredientLine($index);
        $match = $matches->select($this->recipeId, (int) $line->getKey(), $catalogueItemId, $catalogueVersionId, $user);
        $this->catalogueMatches[$line->getKey()] = $this->matchState($match);
        $this->catalogueResults[$line->getKey()] = [];
        $this->refreshFingerprint($fingerprint);
        session()->flash('status', 'Catalogue match saved.');
    }

    public function clearCatalogueMatch(
        int $index,
        RecipeIngredientMatchManager $matches,
        RecipeDraftFingerprint $fingerprint,
    ): void {
        $user = auth()->user();
        if (! $user instanceof User || $this->recipeId === null) {
            abort(403);
        }

        $line = $this->persistedIngredientLine($index);
        $matches->clear($this->recipeId, (int) $line->getKey(), $user);
        unset($this->catalogueMatches[$line->getKey()]);
        $this->refreshFingerprint($fingerprint);
        session()->flash('status', 'Catalogue match cleared.');
    }

    public function confirmCatalogueReplacement(
        int $index,
        RecipeIngredientMatchManager $matches,
        RecipeDraftFingerprint $fingerprint,
    ): void {
        $user = auth()->user();
        if (! $user instanceof User || $this->recipeId === null) {
            abort(403);
        }

        $line = $this->persistedIngredientLine($index);
        $match = $matches->confirmRejectedReplacement(
            $this->recipeId,
            (int) $line->getKey(),
            $user,
        );
        $this->catalogueMatches[$line->getKey()] = $this->matchState($match);
        $this->refreshFingerprint($fingerprint);
        session()->flash('status', 'Approved replacement selected. The ingredient wording was not changed.');
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
        $recipe->load(['ingredientLines.catalogueMatch.catalogueItemVersion.catalogueItem', 'instructionSections', 'instructionSteps']);
        $sectionKeysById = $recipe->instructionSections->mapWithKeys(fn ($section): array => [$section->getKey() => 'section-'.$section->getKey()]);
        $this->recipeId = $recipe->getKey();
        $this->isRevision = $recipe->isFinalized();
        $this->title = $recipe->title;
        $this->servings = $recipe->servings;
        $this->visibility = (string) $recipe->getRawOriginal('visibility');
        $this->ingredients = $recipe->ingredientLines->map(fn ($line): array => [
            'key' => 'ingredient-'.$line->getKey(), 'id' => $line->getKey(), 'original_text' => $line->original_text,
            'quantity' => $line->quantity,
            'unit' => $line->standard_unit instanceof StandardUnit ? MeasurementUnitRegistry::definition($line->standard_unit)->symbol : ($line->custom_unit ?? ''),
            'generic_wording' => $line->generic_wording ?? '', 'notes' => $line->notes ?? '',
        ])->values()->all();
        $this->catalogueSearches = [];
        $this->catalogueResults = [];
        $this->catalogueResultPages = [];
        $this->catalogueMatches = $recipe->ingredientLines
            ->filter(fn ($line): bool => $line->catalogueMatch !== null)
            ->mapWithKeys(fn ($line): array => [$line->getKey() => $this->matchState($line->catalogueMatch)])
            ->all();
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

    private function loadCatalogueResults(int $index, int $page, CatalogueReadQuery $catalogue): void
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $line = $this->persistedIngredientLine($index);
        $lineId = (int) $line->getKey();
        $search = $this->catalogueSearches[$lineId] ?? '';
        validator(['search' => $search], ['search' => ['nullable', 'string', 'max:100']])->validate();
        $results = $catalogue->paginateSelectable($user, $search, max(1, $page));
        $this->catalogueResults[$lineId] = collect($results->items())
            ->map(fn ($candidate): array => $candidate->toArray())
            ->all();
        $this->catalogueResultPages[$lineId] = [
            'page' => $results->currentPage(),
            'last_page' => $results->lastPage(),
            'total' => $results->total(),
        ];
        $this->resetErrorBag('catalogue_match');
    }

    private function persistedIngredientLine(int $index): RecipeIngredientLine
    {
        if ($this->recipeId === null || ! isset($this->ingredients[$index]['id'])) {
            abort(404);
        }

        $recipe = Recipe::query()->findOrFail($this->recipeId);
        $this->authorize('update', $recipe);

        return $recipe->ingredientLines()->findOrFail((int) $this->ingredients[$index]['id']);
    }

    /** @return array{item_id:int, version_id:string, name:string, review_state:string, unavailable:bool, suggested_replacement:?array{id:int,name:string}} */
    private function matchState(RecipeIngredientLineMatch $match): array
    {
        $match->loadMissing('catalogueItemVersion.catalogueItem');
        $version = $match->catalogueItemVersion;
        $item = $version->catalogueItem;
        $item->loadMissing('suggestedReplacement.currentVersion');
        $replacement = null;

        if ($item->status === CatalogueItemStatus::Rejected
            && $item->suggestedReplacement?->status === CatalogueItemStatus::Approved
            && $item->suggestedReplacement->currentVersion !== null) {
            $replacement = [
                'id' => (int) $item->suggestedReplacement->getKey(),
                'name' => trim((string) $item->suggestedReplacement->currentVersion->name)
                    ?: 'Approved catalogue item',
            ];
        }

        return [
            'item_id' => $version->catalogue_item_id,
            'version_id' => (string) $version->getKey(),
            'name' => trim((string) $version->name) ?: 'Unnamed catalogue item',
            'review_state' => $match->getRawOriginal('review_state'),
            'unavailable' => $item->status === CatalogueItemStatus::Rejected,
            'suggested_replacement' => $replacement,
        ];
    }

    private function refreshFingerprint(RecipeDraftFingerprint $fingerprint): void
    {
        if ($this->recipeId !== null) {
            $this->baselineFingerprint = $fingerprint->forRecipe(Recipe::query()->findOrFail($this->recipeId));
        }
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
