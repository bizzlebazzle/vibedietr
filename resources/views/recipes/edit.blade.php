<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-slate-100">{{ $recipe->isFinalized() ? 'Edit private draft revision' : 'Edit recipe draft' }}</h2>
    </x-slot>
    <div class="py-12"><div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
        @if ($recipe->isFinalized())
            <div class="mb-4">
                <a href="{{ route('recipes.show', [$recipe, 'preview' => 'draft']) }}" class="inline-flex rounded border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100">Preview saved draft revision</a>
            </div>
        @endif
        <div class="rounded-lg bg-white p-6 shadow dark:bg-slate-900">@livewire('recipes.form', ['recipe' => $recipe], key('recipe-edit-'.$recipe->id))</div>
    </div></div>
</x-app-layout>
