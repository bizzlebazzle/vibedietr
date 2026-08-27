<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-slate-100">Create recipe draft</h2>
    </x-slot>
    <div class="py-12"><div class="mx-auto max-w-2xl sm:px-6 lg:px-8">
        <div class="mb-4 flex justify-end"><a href="{{ route('recipe-imports.create') }}" class="rounded border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100">Import pasted recipe</a></div>
        <div class="rounded-lg bg-white p-6 shadow dark:bg-slate-900">
        @livewire('recipes.form')
    </div></div></div>
</x-app-layout>
