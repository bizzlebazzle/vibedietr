<section class="space-y-8" aria-labelledby="instructions-heading">
    <div>
        <h3 id="instructions-heading" class="text-lg font-semibold text-gray-900 dark:text-slate-100">Instructions</h3>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Steps keep the exact wording entered. Sections are optional labels.</p>
    </div>

    @if (session('instruction-status'))
        <x-auth-session-status :status="session('instruction-status')" />
    @endif

    <div class="space-y-4" aria-labelledby="instruction-sections-heading">
        <div>
            <h4 id="instruction-sections-heading" class="font-medium text-gray-900 dark:text-slate-100">Sections (optional)</h4>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Steps never need a section. Removing a section leaves its steps unsectioned.</p>
        </div>

        <x-input-error :messages="$errors->get('sectionIds')" />

        @if ($sections->isNotEmpty())
            <ol class="space-y-2">
                @foreach ($sections as $section)
                    <li wire:key="instruction-section-{{ $section->id }}" class="flex flex-wrap items-center justify-between gap-3 rounded border border-gray-200 p-3 dark:border-slate-700">
                        <span class="font-medium text-gray-900 dark:text-slate-100">{{ $section->name }}</span>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="moveSectionUp({{ $section->id }})" @disabled($loop->first) class="rounded border px-3 py-1 text-sm disabled:opacity-40 dark:border-slate-600">Up</button>
                            <button type="button" wire:click="moveSectionDown({{ $section->id }})" @disabled($loop->last) class="rounded border px-3 py-1 text-sm disabled:opacity-40 dark:border-slate-600">Down</button>
                            <button type="button" wire:click="editSection({{ $section->id }})" class="rounded border px-3 py-1 text-sm dark:border-slate-600">Edit</button>
                            <button type="button" wire:click="deleteSection({{ $section->id }})" wire:confirm="Remove this section? Its steps will become unsectioned." class="rounded border border-red-300 px-3 py-1 text-sm text-red-700 dark:border-red-800 dark:text-red-300">Remove</button>
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif

        <form wire:submit="saveSection" class="flex flex-col gap-3 rounded bg-gray-50 p-4 sm:flex-row sm:items-end dark:bg-slate-800">
            <div class="grow">
                <x-input-label for="section-name" value="Section name" />
                <x-text-input id="section-name" wire:model="sectionName" type="text" maxlength="255" class="mt-1 block w-full" required />
                <x-input-error :messages="$errors->get('sectionName')" class="mt-2" />
            </div>
            <div class="flex gap-2">
                <x-primary-button>{{ $editingSectionId === null ? 'Add section' : 'Save section' }}</x-primary-button>
                @if ($editingSectionId !== null)
                    <button type="button" wire:click="cancelSectionEdit" class="rounded border px-4 py-2 text-sm dark:border-slate-600">Cancel</button>
                @endif
            </div>
        </form>
    </div>

    <div class="space-y-4" aria-labelledby="instruction-steps-heading">
        <h4 id="instruction-steps-heading" class="font-medium text-gray-900 dark:text-slate-100">Ordered steps</h4>
        <x-input-error :messages="$errors->get('stepIds')" />

        @if ($steps->isEmpty())
            <p class="rounded border border-dashed border-gray-300 p-4 text-sm text-gray-600 dark:border-slate-700 dark:text-gray-400">No instruction steps yet.</p>
        @else
            <ol class="space-y-3">
                @foreach ($steps as $step)
                    <li wire:key="instruction-step-{{ $step->id }}" class="rounded border border-gray-200 p-4 dark:border-slate-700">
                        <div class="flex gap-3">
                            <span class="font-medium text-gray-500 dark:text-gray-400">{{ $loop->iteration }}.</span>
                            <div class="min-w-0 grow">
                                @if ($step->section !== null)
                                    <p class="mb-1 text-sm font-semibold text-blue-700 dark:text-blue-300">{{ $step->section->name }}</p>
                                @endif
                                <p class="whitespace-pre-wrap text-gray-900 dark:text-slate-100">{{ $step->text }}</p>
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button" wire:click="moveStepUp({{ $step->id }})" @disabled($loop->first) class="rounded border px-3 py-1 text-sm disabled:opacity-40 dark:border-slate-600">Up</button>
                            <button type="button" wire:click="moveStepDown({{ $step->id }})" @disabled($loop->last) class="rounded border px-3 py-1 text-sm disabled:opacity-40 dark:border-slate-600">Down</button>
                            <button type="button" wire:click="editStep({{ $step->id }})" class="rounded border px-3 py-1 text-sm dark:border-slate-600">Edit</button>
                            <button type="button" wire:click="deleteStep({{ $step->id }})" wire:confirm="Remove this instruction step?" class="rounded border border-red-300 px-3 py-1 text-sm text-red-700 dark:border-red-800 dark:text-red-300">Remove</button>
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif

        <form wire:submit="saveStep" class="space-y-4 rounded bg-gray-50 p-4 dark:bg-slate-800">
            <h5 class="font-medium text-gray-900 dark:text-slate-100">{{ $editingStepId === null ? 'Add instruction step' : 'Edit instruction step' }}</h5>
            <div>
                <x-input-label for="instruction-text" value="Instruction text" />
                <textarea id="instruction-text" wire:model="instructionText" rows="4" maxlength="10000" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100" required></textarea>
                <x-input-error :messages="$errors->get('instructionText')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="instruction-section" value="Section (optional)" />
                <select id="instruction-section" wire:model="sectionId" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                    <option value="">No section</option>
                    @foreach ($sections as $section)
                        <option value="{{ $section->id }}">{{ $section->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('sectionId')" class="mt-2" />
            </div>
            <div class="flex gap-2">
                <x-primary-button>{{ $editingStepId === null ? 'Add step' : 'Save step' }}</x-primary-button>
                @if ($editingStepId !== null)
                    <button type="button" wire:click="cancelStepEdit" class="rounded border px-4 py-2 text-sm dark:border-slate-600">Cancel</button>
                @endif
            </div>
        </form>
    </div>
</section>
