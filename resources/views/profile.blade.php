<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-slate-100">
            {{ __('Profile') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:rounded-lg sm:p-8 dark:border-slate-800 dark:bg-slate-900/80">
                <div class="max-w-xl">
                    <livewire:profile.update-profile-information-form />
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:rounded-lg sm:p-8 dark:border-slate-800 dark:bg-slate-900/80">
                <div class="max-w-xl">
                    <livewire:profile.theme-selection />
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:rounded-lg sm:p-8 dark:border-slate-800 dark:bg-slate-900/80">
                <div class="max-w-xl">
                    <livewire:profile.update-password-form />
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:rounded-lg sm:p-8 dark:border-slate-800 dark:bg-slate-900/80">
                <div class="max-w-xl">
                    <livewire:profile.delete-user-form />
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
