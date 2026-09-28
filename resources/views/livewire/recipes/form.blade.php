<form
    wire:submit="save"
    wire:loading.attr="aria-busy"
    wire:target="save,finalize"
    class="recipe-content min-w-0 space-y-8"
    x-data="{
        warn: null,
        pending: false,
        init() {
            this.warn = event => {
                if (this.pending || $wire.unsaved) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            };
            window.addEventListener('beforeunload', this.warn);
        },
        destroy() { window.removeEventListener('beforeunload', this.warn); }
    }"
    x-on:input.capture="if ($event.target.hasAttribute('wire:model') && !$event.target.getAttribute('wire:model').startsWith('catalogueSearches.')) pending = true"
    x-on:change.capture="if ($event.target.hasAttribute('wire:model') && !$event.target.getAttribute('wire:model').startsWith('catalogueSearches.')) pending = true"
    x-on:recipe-saved.window="pending = false"
>
    <x-validation-summary :errors="$errors" message="Please fix the fields before saving or finalizing. Your changes have been kept." />

    <x-input-error :messages="$errors->get('conflict')" />
    <x-input-error :messages="$errors->get('save')" />
    <x-input-error :messages="$errors->get('ingredients')" />
    <x-input-error :messages="$errors->get('sections')" />
    <x-input-error :messages="$errors->get('steps')" />
    <x-input-error :messages="$errors->get('finalize')" />

    @if (session('status'))
        <x-auth-session-status :status="session('status')" />
    @endif

    <div role="status" aria-live="polite" class="recipe-content rounded border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100" x-show="pending || $wire.unsaved" x-cloak>
        {{ $recipeId === null ? 'Draft not created yet. Create draft to save your work.' : 'Unsaved changes. Save draft to keep your work.' }}
    </div>
    @if ($recipeId !== null)
        <div role="status" aria-live="polite" class="rounded border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950/40 dark:text-green-100" x-show="!pending && !$wire.unsaved" x-cloak>All changes saved</div>
    @endif

    <section class="space-y-5" aria-labelledby="recipe-details-heading">
        <div>
            <h3 id="recipe-details-heading" class="text-lg font-semibold text-gray-900 dark:text-slate-100">Recipe details</h3>
            @if ($recipeId !== null)<p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Details and all rows below are saved together.</p>@endif
        </div>
        <div>
            <x-input-label for="title" value="Title" />
            <x-text-input id="title" wire:model="title" type="text" class="mt-1 block w-full" required autofocus />
            <x-input-error :messages="$errors->get('title')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="servings" value="Suggested servings (optional for a draft; required to finalize)" />
            <x-text-input id="servings" wire:model="servings" type="number" min="0.01" step="0.01" class="mt-1 block w-full" />
            <x-input-error :messages="$errors->get('servings')" class="mt-2" />
        </div>
        <fieldset>
            <legend class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $isRevision ? 'Current recipe visibility' : 'Visibility when finalized' }}</legend>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $isRevision ? 'A revision is always private. Change public/private visibility separately from the finalized recipe page.' : 'The draft remains private. Finalized recipes are public by default; choose private explicitly to keep the finalized recipe owner-only.' }}</p>
            <div class="mt-3 space-y-2">
                @foreach ($visibilityOptions as $option)
                    <label class="flex items-center gap-2"><input wire:model="visibility" type="radio" value="{{ $option->value }}" @disabled($isRevision)><span>{{ ucfirst($option->value) }}</span></label>
                @endforeach
            </div>
            <x-input-error :messages="$errors->get('visibility')" class="mt-2" />
        </fieldset>
    </section>

    @if ($recipeId !== null)
        <section class="space-y-4 border-t border-gray-200 pt-8 dark:border-slate-700" aria-labelledby="ingredients-heading">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div><h3 id="ingredients-heading" class="text-lg font-semibold text-gray-900 dark:text-slate-100">Ingredients</h3><p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Original wording is preserved exactly; structured details are optional.</p></div>
                <button type="button" wire:click="addIngredient" class="inline-flex min-h-11 items-center rounded border px-3 py-2 text-sm dark:border-slate-600">Add ingredient line</button>
            </div>
            @forelse ($ingredients as $index => $line)
                <fieldset id="ingredient-line-{{ $index + 1 }}" wire:key="{{ $line['key'] }}" class="min-w-0 scroll-mt-4 space-y-4 rounded border border-gray-200 p-4 dark:border-slate-700">
                    <legend class="px-1 font-medium text-gray-900 dark:text-slate-100">Ingredient {{ $index + 1 }}</legend>
                    <div>
                        <x-input-label for="ingredient-{{ $line['key'] }}-text" value="Original ingredient line" />
                        <textarea id="ingredient-{{ $line['key'] }}-text" wire:model="ingredients.{{ $index }}.original_text" rows="3" maxlength="10000" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100" required></textarea>
                        <x-input-error :messages="$errors->get('ingredients.'.$index.'.original_text')" class="mt-2" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><x-input-label for="ingredient-{{ $line['key'] }}-quantity" value="Quantity (optional)" /><x-text-input id="ingredient-{{ $line['key'] }}-quantity" wire:model="ingredients.{{ $index }}.quantity" type="text" inputmode="decimal" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('ingredients.'.$index.'.quantity')" class="mt-2" /></div>
                        <div><x-input-label for="ingredient-{{ $line['key'] }}-unit" value="Unit (optional)" /><x-text-input id="ingredient-{{ $line['key'] }}-unit" wire:model="ingredients.{{ $index }}.unit" type="text" list="recipe-unit-options" maxlength="32" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('ingredients.'.$index.'.unit')" class="mt-2" /></div>
                    </div>
                    <div><x-input-label for="ingredient-{{ $line['key'] }}-wording" value="Generic ingredient wording (optional)" /><x-text-input id="ingredient-{{ $line['key'] }}-wording" wire:model="ingredients.{{ $index }}.generic_wording" type="text" maxlength="255" class="mt-1 block w-full" /><x-input-error :messages="$errors->get('ingredients.'.$index.'.generic_wording')" class="mt-2" /></div>
                    <div><x-input-label for="ingredient-{{ $line['key'] }}-notes" value="Notes (optional)" /><textarea id="ingredient-{{ $line['key'] }}-notes" wire:model="ingredients.{{ $index }}.notes" rows="2" maxlength="2000" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100"></textarea><x-input-error :messages="$errors->get('ingredients.'.$index.'.notes')" class="mt-2" /></div>
                    @if ($line['id'] !== null)
                        @php
                            $lineId = (int) $line['id'];
                            $currentMatch = $catalogueMatches[$lineId] ?? null;
                            $resultPage = $catalogueResultPages[$lineId] ?? null;
                        @endphp
                        <section class="space-y-3 rounded bg-gray-50 p-3 dark:bg-slate-800" aria-label="Catalogue match for ingredient {{ $index + 1 }}">
                            <div aria-live="polite">
                                @if ($currentMatch)
                                    <p class="text-sm text-gray-800 dark:text-gray-200">
                                        Matched to <strong>{{ $currentMatch['name'] }}</strong>
                                        @if($currentMatch['unavailable'])
                                            <span class="font-medium text-red-700 dark:text-red-300">(unavailable; review required)</span>
                                        @else
                                            <span class="text-gray-600 dark:text-gray-400">(confirmed selection)</span>
                                        @endif
                                    </p>
                                    @if($currentMatch['unavailable'])
                                        <p class="mt-1 text-sm text-red-700 dark:text-red-300">The original ingredient text is unchanged. No replacement has been applied automatically.</p>
                                        @if($currentMatch['suggested_replacement'])
                                            <button type="button" wire:click="confirmCatalogueReplacement({{ $index }})" aria-label="Use suggested approved food for ingredient {{ $index + 1 }}" class="mt-2 inline-flex min-h-11 items-center rounded border border-sky-400 px-3 py-2 text-sm">
                                                Use suggested approved food: {{ $currentMatch['suggested_replacement']['name'] }}
                                            </button>
                                        @endif
                                    @endif
                                @else
                                    <p class="text-sm text-gray-600 dark:text-gray-400">No catalogue match selected.</p>
                                @endif
                            </div>
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                                <div class="min-w-0 grow">
                                    <x-input-label for="ingredient-{{ $line['key'] }}-catalogue-search" value="Search catalogue by name or barcode" />
                                    <x-text-input id="ingredient-{{ $line['key'] }}-catalogue-search" wire:model="catalogueSearches.{{ $lineId }}" type="search" maxlength="100" class="mt-1 block w-full" />
                                    <x-input-error :messages="$errors->get('search')" class="mt-2" />
                                </div>
                                <button type="button" wire:click="searchCatalogue({{ $index }})" aria-label="Search catalogue for ingredient {{ $index + 1 }}" class="inline-flex min-h-11 items-center rounded border px-3 py-2 text-sm dark:border-slate-600">
                                    {{ $currentMatch ? 'Search to replace' : 'Search' }}
                                </button>
                                @if ($currentMatch)
                                    <button type="button" wire:click="clearCatalogueMatch({{ $index }})" aria-label="Clear catalogue match for ingredient {{ $index + 1 }}" wire:confirm="Clear this catalogue match? The ingredient line will remain unchanged." class="inline-flex min-h-11 items-center rounded border border-red-300 px-3 py-2 text-sm text-red-700 dark:border-red-800 dark:text-red-300">Clear match</button>
                                @endif
                            </div>
                            <x-input-error :messages="$errors->get('catalogue_match')" />
                            @if (array_key_exists($lineId, $catalogueResults))
                                <p class="text-xs text-gray-600 dark:text-gray-400">{{ $resultPage['total'] ?? 0 }} selectable result(s). Pending manual foods appear only to their submitter.</p>
                                @if ($catalogueResults[$lineId] === [])
                                    <p class="text-sm text-gray-600 dark:text-gray-400">No selectable catalogue records found.</p>
                                @else
                                    <ul class="space-y-2" aria-label="Catalogue search results">
                                        @foreach ($catalogueResults[$lineId] as $candidate)
                                            <li class="flex flex-wrap items-center justify-between gap-2 rounded border border-gray-200 bg-white p-2 text-sm dark:border-slate-700 dark:bg-slate-900">
                                                <span>
                                                    <strong>{{ $candidate['name'] }}</strong>
                                                    @if ($candidate['barcode'])<span class="text-gray-600 dark:text-gray-400">Barcode {{ $candidate['barcode'] }}</span>@endif
                                                    @if ($candidate['pending'])<span class="rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-900 dark:bg-amber-900/40 dark:text-amber-100">Your pending item</span>@endif
                                                </span>
                                                <button type="button" wire:click="selectCatalogueMatch({{ $index }}, {{ $candidate['item_id'] }}, '{{ $candidate['version_id'] }}')" class="inline-flex min-h-11 items-center rounded border px-3 py-2 dark:border-slate-600">
                                                    {{ $currentMatch ? 'Replace with this' : 'Select' }}
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                    @if ($resultPage && $resultPage['last_page'] > 1)
                                        <nav class="flex flex-wrap items-center justify-between gap-2 text-sm" aria-label="Catalogue result pages">
                                            <button type="button" wire:click="changeCataloguePage({{ $index }}, {{ $resultPage['page'] - 1 }})" @disabled($resultPage['page'] <= 1) class="inline-flex min-h-11 items-center rounded border px-3 py-2 disabled:opacity-40 dark:border-slate-600">Previous</button>
                                            <span>Page {{ $resultPage['page'] }} of {{ $resultPage['last_page'] }}</span>
                                            <button type="button" wire:click="changeCataloguePage({{ $index }}, {{ $resultPage['page'] + 1 }})" @disabled($resultPage['page'] >= $resultPage['last_page']) class="inline-flex min-h-11 items-center rounded border px-3 py-2 disabled:opacity-40 dark:border-slate-600">Next</button>
                                        </nav>
                                    @endif
                                @endif
                            @endif
                        </section>
                    @else
                        <p class="text-sm text-gray-600 dark:text-gray-400">Save the draft before matching this new ingredient line.</p>
                    @endif
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="moveIngredientUp({{ $index }})" aria-label="Move ingredient {{ $index + 1 }} up" @disabled($loop->first) class="inline-flex min-h-11 min-w-11 items-center justify-center rounded border px-3 py-2 text-sm disabled:opacity-40 dark:border-slate-600">Up</button>
                        <button type="button" wire:click="moveIngredientDown({{ $index }})" aria-label="Move ingredient {{ $index + 1 }} down" @disabled($loop->last) class="inline-flex min-h-11 min-w-11 items-center justify-center rounded border px-3 py-2 text-sm disabled:opacity-40 dark:border-slate-600">Down</button>
                        <button type="button" wire:click="removeIngredient({{ $index }})" aria-label="Remove ingredient {{ $index + 1 }}" wire:confirm="Remove this ingredient line? It will be removed from the recipe." class="inline-flex min-h-11 items-center rounded border border-red-300 px-3 py-2 text-sm text-red-700 dark:border-red-800 dark:text-red-300">Remove</button>
                    </div>
                </fieldset>
            @empty
                <p class="rounded border border-dashed border-gray-300 p-4 text-sm text-gray-600 dark:border-slate-700 dark:text-gray-400">No ingredient lines yet.</p>
            @endforelse
            <datalist id="recipe-unit-options">
                @foreach ($unitGroups as $options) @foreach ($options as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach @endforeach
                @foreach ($customUnits as $customUnit)<option value="{{ $customUnit }}">Custom unit</option>@endforeach
            </datalist>
        </section>

        <section class="space-y-6 border-t border-gray-200 pt-8 dark:border-slate-700" aria-labelledby="instructions-heading">
            <div><h3 id="instructions-heading" class="text-lg font-semibold text-gray-900 dark:text-slate-100">Instructions</h3><p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Step wording is preserved exactly. Sections are optional.</p></div>
            <div class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3"><h4 class="font-medium text-gray-900 dark:text-slate-100">Sections</h4><button type="button" wire:click="addSection" class="inline-flex min-h-11 items-center rounded border px-3 py-2 text-sm dark:border-slate-600">Add section</button></div>
                @foreach ($sections as $index => $section)
                    <div wire:key="{{ $section['key'] }}" class="min-w-0 rounded border border-gray-200 p-4 dark:border-slate-700">
                        <x-input-label for="section-{{ $section['key'] }}" :value="'Section '.($index + 1).' name'" />
                        <x-text-input id="section-{{ $section['key'] }}" wire:model="sections.{{ $index }}.name" type="text" maxlength="255" class="mt-1 block w-full" required />
                        <x-input-error :messages="$errors->get('sections.'.$index.'.name')" class="mt-2" />
                        <div class="mt-3 flex flex-wrap gap-2"><button type="button" wire:click="moveSectionUp({{ $index }})" aria-label="Move section {{ $index + 1 }} up" @disabled($loop->first) class="inline-flex min-h-11 min-w-11 items-center justify-center rounded border px-3 py-2 text-sm disabled:opacity-40 dark:border-slate-600">Up</button><button type="button" wire:click="moveSectionDown({{ $index }})" aria-label="Move section {{ $index + 1 }} down" @disabled($loop->last) class="inline-flex min-h-11 min-w-11 items-center justify-center rounded border px-3 py-2 text-sm disabled:opacity-40 dark:border-slate-600">Down</button><button type="button" wire:click="removeSection({{ $index }})" aria-label="Remove section {{ $index + 1 }}" wire:confirm="Remove this section? Its steps will become unsectioned." class="inline-flex min-h-11 items-center rounded border border-red-300 px-3 py-2 text-sm text-red-700 dark:border-red-800 dark:text-red-300">Remove</button></div>
                    </div>
                @endforeach
            </div>
            <div class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3"><h4 class="font-medium text-gray-900 dark:text-slate-100">Ordered steps</h4><button type="button" wire:click="addStep" class="inline-flex min-h-11 items-center rounded border px-3 py-2 text-sm dark:border-slate-600">Add step</button></div>
                @forelse ($steps as $index => $step)
                    <fieldset wire:key="{{ $step['key'] }}" class="min-w-0 space-y-4 rounded border border-gray-200 p-4 dark:border-slate-700">
                        <legend class="px-1 font-medium text-gray-900 dark:text-slate-100">Step {{ $index + 1 }}</legend>
                        <div><x-input-label for="step-{{ $step['key'] }}-text" value="Instruction text" /><textarea id="step-{{ $step['key'] }}-text" wire:model="steps.{{ $index }}.text" rows="4" maxlength="10000" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100" required></textarea><x-input-error :messages="$errors->get('steps.'.$index.'.text')" class="mt-2" /></div>
                        <div><x-input-label for="step-{{ $step['key'] }}-section" value="Section (optional)" /><select id="step-{{ $step['key'] }}-section" wire:model="steps.{{ $index }}.section_key" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100"><option value="">No section</option>@foreach ($sections as $section)<option value="{{ $section['key'] }}">{{ $section['name'] !== '' ? $section['name'] : 'Unnamed section' }}</option>@endforeach</select><x-input-error :messages="$errors->get('steps.'.$index.'.section_key')" class="mt-2" /></div>
                        <div class="flex flex-wrap gap-2"><button type="button" wire:click="moveStepUp({{ $index }})" aria-label="Move step {{ $index + 1 }} up" @disabled($loop->first) class="inline-flex min-h-11 min-w-11 items-center justify-center rounded border px-3 py-2 text-sm disabled:opacity-40 dark:border-slate-600">Up</button><button type="button" wire:click="moveStepDown({{ $index }})" aria-label="Move step {{ $index + 1 }} down" @disabled($loop->last) class="inline-flex min-h-11 min-w-11 items-center justify-center rounded border px-3 py-2 text-sm disabled:opacity-40 dark:border-slate-600">Down</button><button type="button" wire:click="removeStep({{ $index }})" aria-label="Remove step {{ $index + 1 }}" wire:confirm="Remove this instruction step? Its text will be removed from the recipe." class="inline-flex min-h-11 items-center rounded border border-red-300 px-3 py-2 text-sm text-red-700 dark:border-red-800 dark:text-red-300">Remove</button></div>
                    </fieldset>
                @empty
                    <p class="rounded border border-dashed border-gray-300 p-4 text-sm text-gray-600 dark:border-slate-700 dark:text-gray-400">No instruction steps yet.</p>
                @endforelse
            </div>
        </section>
    @endif

    <div class="sticky bottom-0 z-10 flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 bg-white py-3 dark:border-slate-700 dark:bg-slate-900">
        <span class="text-sm text-gray-600 dark:text-gray-400"><span x-show="pending || $wire.unsaved" x-cloak>{{ $recipeId === null ? 'Draft not created yet.' : 'Changes are not saved yet.' }}</span></span>
        <div class="flex flex-wrap items-center justify-end gap-2">
            <x-primary-button class="min-h-11" wire:loading.attr="disabled" wire:target="save">{{ $recipeId === null ? 'Create draft' : 'Save draft' }}</x-primary-button>
            <span role="status" aria-live="polite" wire:loading wire:target="save">Saving draft…</span>
            @if ($recipeId !== null)
                <button type="button" wire:click="finalize" wire:loading.attr="disabled" wire:target="finalize" wire:confirm="{{ $isRevision ? 'Publish this private draft as the next immutable recipe version?' : 'Finalize this recipe using the visible editor content?' }}" class="inline-flex min-h-11 items-center rounded bg-green-700 px-4 py-2 text-sm font-semibold text-white hover:bg-green-600">{{ $isRevision ? 'Publish revision' : 'Finalize recipe' }}</button>
                <span role="status" aria-live="polite" wire:loading wire:target="finalize">Finalizing recipe…</span>
            @endif
        </div>
    </div>
</form>
