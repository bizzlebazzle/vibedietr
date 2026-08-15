<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-slate-100">Edit recipe draft</h2>
    </x-slot>
    <div class="py-12"><div class="mx-auto max-w-2xl space-y-6 sm:px-6 lg:px-8">
        <div class="rounded-lg bg-white p-6 shadow dark:bg-slate-900">@livewire('recipes.form', ['recipe' => $recipe], key('recipe-edit-'.$recipe->id))</div>
        <div class="rounded-lg bg-white p-6 shadow dark:bg-slate-900">@livewire('recipes.ingredient-lines', ['recipe' => $recipe], key('recipe-ingredient-lines-'.$recipe->id))</div>
    </div></div>
</x-app-layout>
