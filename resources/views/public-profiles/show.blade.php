@auth
    <x-app-layout>
        <x-slot name="title">{{ $profile->attributionName }}</x-slot>
        <div class="py-12">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                @include('public-profiles.partials.detail')
            </div>
        </div>
    </x-app-layout>
@else
    <x-guest-layout>
        <x-slot name="title">{{ $profile->attributionName }}</x-slot>
        @include('public-profiles.partials.detail')
    </x-guest-layout>
@endauth
