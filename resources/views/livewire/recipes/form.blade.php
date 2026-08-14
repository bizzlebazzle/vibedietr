<form wire:submit="save" class="space-y-6">
    @if ($errors->any())
        <div class="rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-100">
            Please fix the highlighted fields before saving.
        </div>
    @endif

    <div>
        <x-input-label for="title" value="Title" />
        <x-text-input id="title" wire:model="title" type="text" class="mt-1 block w-full" required autofocus />
        <x-input-error :messages="$errors->get('title')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="servings" value="Suggested servings (optional)" />
        <x-text-input id="servings" wire:model="servings" type="number" min="0.01" step="0.01" class="mt-1 block w-full" />
        <x-input-error :messages="$errors->get('servings')" class="mt-2" />
    </div>

    <fieldset>
        <legend class="text-sm font-medium text-gray-700 dark:text-gray-300">Intended visibility</legend>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">This draft remains private until a later publishing workflow finalizes it.</p>
        <div class="mt-3 space-y-2">
            @foreach ($visibilityOptions as $option)
                <label class="flex items-center gap-2">
                    <input wire:model="visibility" type="radio" value="{{ $option->value }}">
                    <span>{{ ucfirst($option->value) }}</span>
                </label>
            @endforeach
        </div>
        <x-input-error :messages="$errors->get('visibility')" class="mt-2" />
    </fieldset>

    <x-primary-button>Save draft</x-primary-button>
</form>
