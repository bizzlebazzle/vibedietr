@auth
    <x-app-layout>
        <x-slot name="header">
            <h1 class="text-xl font-semibold text-gray-800 dark:text-slate-100">{{ $profile->attributionName }}</h1>
        </x-slot>
        <div class="py-12">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                @include('public-profiles.partials.detail')
            </div>
        </div>
    </x-app-layout>
@else
    <x-guest-layout>
        @include('public-profiles.partials.detail')
    </x-guest-layout>
@endauth
