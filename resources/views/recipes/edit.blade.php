<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-800 dark:text-slate-100">{{ $recipe->isFinalized() ? 'Edit private draft revision' : 'Edit recipe draft' }}</h1>
    </x-slot>
    <div class="py-12"><div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
        @include('recipes.partials.import-review', ['import' => $recipe->sourceImport])
        @if ($recipe->isFinalized())
            <div class="mb-4">
                <a href="{{ route('recipes.show', [$recipe, 'preview' => 'draft']) }}" class="inline-flex rounded border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100">Preview saved draft revision</a>
            </div>
        @endif
        <div class="min-w-0 rounded-lg bg-white p-4 shadow dark:bg-slate-900 sm:p-6">@livewire('recipes.form', ['recipe' => $recipe], key('recipe-edit-'.$recipe->id))</div>
    </div></div>
</x-app-layout>
