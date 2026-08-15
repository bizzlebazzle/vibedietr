<section class="space-y-6" aria-labelledby="ingredient-lines-heading">
    <div>
        <h3 id="ingredient-lines-heading" class="text-lg font-semibold text-gray-900 dark:text-slate-100">Ingredient lines</h3>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">The original line is kept exactly as entered. Structured details are optional.</p>
    </div>

    @if (session('ingredient-status'))
        <x-auth-session-status :status="session('ingredient-status')" />
    @endif

    <x-input-error :messages="$errors->get('lineIds')" />

    @if ($lines->isEmpty())
        <p class="rounded border border-dashed border-gray-300 p-4 text-sm text-gray-600 dark:border-slate-700 dark:text-gray-400">No ingredient lines yet.</p>
    @else
        <ol class="space-y-3">
            @foreach ($lines as $line)
                <li wire:key="ingredient-line-{{ $line->id }}" class="rounded border border-gray-200 p-4 dark:border-slate-700">
                    <p class="whitespace-pre-wrap text-gray-900 dark:text-slate-100">{{ $line->original_text }}</p>
                    @if ($line->quantity !== null || $line->standard_unit !== null || $line->custom_unit !== null || $line->generic_wording !== null || $line->notes !== null)
                        <dl class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                            @if ($line->quantity !== null)<div><dt class="inline font-medium">Quantity:</dt> <dd class="inline">{{ $line->quantity }}</dd></div>@endif
                            @if ($line->standard_unit !== null)<div><dt class="inline font-medium">Unit:</dt> <dd class="inline">{{ \App\Domain\Measurements\MeasurementUnitRegistry::definition($line->standard_unit)->symbol }}</dd></div>@endif
                            @if ($line->custom_unit !== null)<div><dt class="inline font-medium">Custom unit:</dt> <dd class="inline">{{ $line->custom_unit }}</dd></div>@endif
                            @if ($line->generic_wording !== null)<div><dt class="inline font-medium">Ingredient:</dt> <dd class="inline">{{ $line->generic_wording }}</dd></div>@endif
                            @if ($line->notes !== null)<div><dt class="inline font-medium">Notes:</dt> <dd class="inline">{{ $line->notes }}</dd></div>@endif
                        </dl>
                    @endif
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" wire:click="moveUp({{ $line->id }})" @disabled($loop->first) class="rounded border px-3 py-1 text-sm disabled:opacity-40 dark:border-slate-600">Up</button>
                        <button type="button" wire:click="moveDown({{ $line->id }})" @disabled($loop->last) class="rounded border px-3 py-1 text-sm disabled:opacity-40 dark:border-slate-600">Down</button>
                        <button type="button" wire:click="editLine({{ $line->id }})" class="rounded border px-3 py-1 text-sm dark:border-slate-600">Edit</button>
                        <button type="button" wire:click="deleteLine({{ $line->id }})" wire:confirm="Remove this ingredient line?" class="rounded border border-red-300 px-3 py-1 text-sm text-red-700 dark:border-red-800 dark:text-red-300">Remove</button>
                    </div>
                </li>
            @endforeach
        </ol>
    @endif

    <form wire:submit="saveLine" class="space-y-4 rounded bg-gray-50 p-4 dark:bg-slate-800">
        <h4 class="font-medium text-gray-900 dark:text-slate-100">{{ $editingLineId === null ? 'Add ingredient line' : 'Edit ingredient line' }}</h4>
        <div>
            <x-input-label for="original-text" value="Original ingredient line" />
            <textarea id="original-text" wire:model="originalText" rows="3" maxlength="10000" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100" required></textarea>
            <x-input-error :messages="$errors->get('originalText')" class="mt-2" />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <x-input-label for="line-quantity" value="Quantity (optional)" />
                <x-text-input id="line-quantity" wire:model="quantity" type="text" inputmode="decimal" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('quantity')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="line-unit" value="Unit (optional)" />
                <x-text-input id="line-unit" wire:model="unit" type="text" list="recipe-unit-options" maxlength="32" class="mt-1 block w-full" />
                <datalist id="recipe-unit-options">
                    @foreach ($unitGroups as $options)
                        @foreach ($options as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    @endforeach
                    @foreach ($customUnits as $customUnit)<option value="{{ $customUnit }}">Custom unit</option>@endforeach
                </datalist>
                <x-input-error :messages="$errors->get('unit')" class="mt-2" />
            </div>
        </div>
        <div>
            <x-input-label for="generic-wording" value="Generic ingredient wording (optional)" />
            <x-text-input id="generic-wording" wire:model="genericWording" type="text" maxlength="255" class="mt-1 block w-full" />
            <x-input-error :messages="$errors->get('genericWording')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="line-notes" value="Notes (optional)" />
            <textarea id="line-notes" wire:model="notes" rows="2" maxlength="2000" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100"></textarea>
            <x-input-error :messages="$errors->get('notes')" class="mt-2" />
        </div>
        <div class="flex gap-2">
            <x-primary-button>{{ $editingLineId === null ? 'Add line' : 'Save line' }}</x-primary-button>
            @if ($editingLineId !== null)<button type="button" wire:click="cancelEdit" class="rounded border px-4 py-2 text-sm dark:border-slate-600">Cancel</button>@endif
        </div>
    </form>
</section>
