<form
    wire:submit="save"
    class="space-y-8"
    x-data="{
        warn: null,
        init() {
            this.warn = event => {
                if ($wire.unsaved) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            };
            window.addEventListener('beforeunload', this.warn);
        },
        destroy() { window.removeEventListener('beforeunload', this.warn); }
    }"
>
    @if ($errors->any())
        <div role="alert" class="rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-100">
            Please fix the highlighted fields before saving or finalizing. Your changes have been kept.
        </div>
    @endif

    <x-input-error :messages="$errors->get('conflict')" />
    <x-input-error :messages="$errors->get('save')" />
    <x-input-error :messages="$errors->get('ingredients')" />
    <x-input-error :messages="$errors->get('sections')" />
    <x-input-error :messages="$errors->get('steps')" />
    <x-input-error :messages="$errors->get('finalize')" />

    @if (session('status'))
        <x-auth-session-status :status="session('status')" />
    @endif

    @if ($recipeId !== null)
        <div aria-live="polite" class="rounded px-4 py-3 text-sm {{ $unsaved ? 'border border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100' : 'border border-green-200 bg-green-50 text-green-800 dark:border-green-900 dark:bg-green-950/40 dark:text-green-100' }}">
            {{ $unsaved ? 'Unsaved changes' : 'All changes saved' }}
        </div>
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
            <legend class="text-sm font-medium text-gray-700 dark:text-gray-300">Visibility when finalized</legend>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">The draft remains private. Finalized recipes are public by default; choose private explicitly to keep the finalized recipe owner-only.</p>
            <div class="mt-3 space-y-2">
                @foreach ($visibilityOptions as $option)
                    <label class="flex items-center gap-2"><input wire:model="visibility" type="radio" value="{{ $option->value }}"><span>{{ ucfirst($option->value) }}</span></label>
                @endforeach
            </div>
            <x-input-error :messages="$errors->get('visibility')" class="mt-2" />
        </fieldset>
    </section>

    @if ($recipeId !== null)
        <section class="space-y-4 border-t border-gray-200 pt-8 dark:border-slate-700" aria-labelledby="ingredients-heading">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div><h3 id="ingredients-heading" class="text-lg font-semibold text-gray-900 dark:text-slate-100">Ingredients</h3><p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Original wording is preserved exactly; structured details are optional.</p></div>
                <button type="button" wire:click="addIngredient" class="rounded border px-3 py-2 text-sm dark:border-slate-600">Add ingredient line</button>
            </div>
            @forelse ($ingredients as $index => $line)
                <fieldset wire:key="{{ $line['key'] }}" class="space-y-4 rounded border border-gray-200 p-4 dark:border-slate-700">
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
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="moveIngredientUp({{ $index }})" @disabled($loop->first) class="rounded border px-3 py-1 text-sm disabled:opacity-40 dark:border-slate-600">Up</button>
                        <button type="button" wire:click="moveIngredientDown({{ $index }})" @disabled($loop->last) class="rounded border px-3 py-1 text-sm disabled:opacity-40 dark:border-slate-600">Down</button>
                        <button type="button" wire:click="removeIngredient({{ $index }})" wire:confirm="Remove this ingredient line?" class="rounded border border-red-300 px-3 py-1 text-sm text-red-700 dark:border-red-800 dark:text-red-300">Remove</button>
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
                <div class="flex items-center justify-between gap-3"><h4 class="font-medium text-gray-900 dark:text-slate-100">Sections</h4><button type="button" wire:click="addSection" class="rounded border px-3 py-2 text-sm dark:border-slate-600">Add section</button></div>
                @foreach ($sections as $index => $section)
                    <div wire:key="{{ $section['key'] }}" class="rounded border border-gray-200 p-4 dark:border-slate-700">
                        <x-input-label for="section-{{ $section['key'] }}" :value="'Section '.($index + 1).' name'" />
                        <x-text-input id="section-{{ $section['key'] }}" wire:model="sections.{{ $index }}.name" type="text" maxlength="255" class="mt-1 block w-full" required />
                        <x-input-error :messages="$errors->get('sections.'.$index.'.name')" class="mt-2" />
                        <div class="mt-3 flex flex-wrap gap-2"><button type="button" wire:click="moveSectionUp({{ $index }})" @disabled($loop->first) class="rounded border px-3 py-1 text-sm disabled:opacity-40 dark:border-slate-600">Up</button><button type="button" wire:click="moveSectionDown({{ $index }})" @disabled($loop->last) class="rounded border px-3 py-1 text-sm disabled:opacity-40 dark:border-slate-600">Down</button><button type="button" wire:click="removeSection({{ $index }})" wire:confirm="Remove this section? Its steps will become unsectioned." class="rounded border border-red-300 px-3 py-1 text-sm text-red-700 dark:border-red-800 dark:text-red-300">Remove</button></div>
                    </div>
                @endforeach
            </div>
            <div class="space-y-4">
                <div class="flex items-center justify-between gap-3"><h4 class="font-medium text-gray-900 dark:text-slate-100">Ordered steps</h4><button type="button" wire:click="addStep" class="rounded border px-3 py-2 text-sm dark:border-slate-600">Add step</button></div>
                @forelse ($steps as $index => $step)
                    <fieldset wire:key="{{ $step['key'] }}" class="space-y-4 rounded border border-gray-200 p-4 dark:border-slate-700">
                        <legend class="px-1 font-medium text-gray-900 dark:text-slate-100">Step {{ $index + 1 }}</legend>
                        <div><x-input-label for="step-{{ $step['key'] }}-text" value="Instruction text" /><textarea id="step-{{ $step['key'] }}-text" wire:model="steps.{{ $index }}.text" rows="4" maxlength="10000" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100" required></textarea><x-input-error :messages="$errors->get('steps.'.$index.'.text')" class="mt-2" /></div>
                        <div><x-input-label for="step-{{ $step['key'] }}-section" value="Section (optional)" /><select id="step-{{ $step['key'] }}-section" wire:model="steps.{{ $index }}.section_key" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100"><option value="">No section</option>@foreach ($sections as $section)<option value="{{ $section['key'] }}">{{ $section['name'] !== '' ? $section['name'] : 'Unnamed section' }}</option>@endforeach</select><x-input-error :messages="$errors->get('steps.'.$index.'.section_key')" class="mt-2" /></div>
                        <div class="flex flex-wrap gap-2"><button type="button" wire:click="moveStepUp({{ $index }})" @disabled($loop->first) class="rounded border px-3 py-1 text-sm disabled:opacity-40 dark:border-slate-600">Up</button><button type="button" wire:click="moveStepDown({{ $index }})" @disabled($loop->last) class="rounded border px-3 py-1 text-sm disabled:opacity-40 dark:border-slate-600">Down</button><button type="button" wire:click="removeStep({{ $index }})" wire:confirm="Remove this instruction step?" class="rounded border border-red-300 px-3 py-1 text-sm text-red-700 dark:border-red-800 dark:text-red-300">Remove</button></div>
                    </fieldset>
                @empty
                    <p class="rounded border border-dashed border-gray-300 p-4 text-sm text-gray-600 dark:border-slate-700 dark:text-gray-400">No instruction steps yet.</p>
                @endforelse
            </div>
        </section>
    @endif

    <div class="sticky bottom-0 flex items-center justify-between gap-4 border-t border-gray-200 bg-white py-4 dark:border-slate-700 dark:bg-slate-900">
        <span class="text-sm text-gray-600 dark:text-gray-400">{{ $recipeId !== null && $unsaved ? 'Changes are not saved yet.' : '' }}</span>
        <div class="flex flex-wrap justify-end gap-3">
            <x-primary-button>{{ $recipeId === null ? 'Create draft' : 'Save draft' }}</x-primary-button>
            @if ($recipeId !== null)
                <button type="button" wire:click="finalize" wire:confirm="Finalize this recipe using the visible editor content? Later editing is deferred to recipe revisions." class="rounded bg-green-700 px-4 py-2 text-sm font-semibold text-white hover:bg-green-600">Finalize recipe</button>
            @endif
        </div>
    </div>
</form>
