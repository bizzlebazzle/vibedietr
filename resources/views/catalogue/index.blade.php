@auth
    <x-app-layout>
        <x-slot name="header">
            <h2 class="text-xl font-semibold text-gray-800 dark:text-slate-100">Food catalogue</h2>
        </x-slot>
        <div class="py-12">
            <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                @livewire('catalogue.index')
            </div>
        </div>
    </x-app-layout>
@else
    <x-guest-layout>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-slate-100">Food catalogue</h1>
        <div class="mt-6">
            @livewire('catalogue.index')
        </div>
    </x-guest-layout>
@endauth
