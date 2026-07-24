<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-slate-100">
            Edit ingredient
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-5xl sm:px-6 lg:px-8">
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900/80 sm:p-6">
                @livewire('ingredients.form', ['ingredient' => $ingredient], key('ingredient-edit-'.$ingredient->id))
            </div>
        </div>
    </div>
</x-app-layout>
