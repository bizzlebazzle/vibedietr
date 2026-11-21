@auth
    <x-app-layout>
        <div class="py-12">
            <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                @include('recipes.partials.navigation')
                @include('recipes.partials.'.($view === 'discover' ? 'discovery' : ($view === 'bookmarks' ? 'bookmarks' : 'owned')))
            </div>
        </div>
    </x-app-layout>
@else
    <x-guest-layout>
        @include('recipes.partials.discovery')
    </x-guest-layout>
@endauth
