<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-slate-100">
            {{ $ingredient->name }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-6xl space-y-6 sm:px-6 lg:px-8">
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900/80 sm:p-6">
                @if (session('status'))
                    <x-auth-session-status class="mb-4" :status="session('status')" />
                @endif

                @livewire('ingredients.show', ['ingredient' => $ingredient], key('ingredient-show-'.$ingredient->id))
            </div>
        </div>
    </div>
</x-app-layout>
