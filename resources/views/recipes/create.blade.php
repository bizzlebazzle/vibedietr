<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-slate-100">Create recipe draft</h2>
    </x-slot>
    <div class="py-12"><div class="mx-auto max-w-2xl sm:px-6 lg:px-8"><div class="rounded-lg bg-white p-6 shadow dark:bg-slate-900">
        @livewire('recipes.form')
    </div></div></div>
</x-app-layout>
