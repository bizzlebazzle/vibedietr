@auth
    <x-app-layout>
        <x-slot name="header">
            <h2 class="text-xl font-semibold text-gray-800 dark:text-slate-100">{{ $item->name }}</h2>
        </x-slot>
        <div class="py-12">
            <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8 space-y-4">
                @if(session('status'))<x-auth-session-status :status="session('status')" class="mb-4" />@endif
                @include('catalogue.partials.detail')
                @if($canRequestProviderRefresh)
                    <form method="POST" action="{{ route('admin.catalogue.provider-refreshes.store', $item->id) }}">
                        @csrf
                        <x-primary-button>Check OpenFoodFacts for updates</x-primary-button>
                    </form>
                @endif
            </div>
        </div>
    </x-app-layout>
@else
    <x-guest-layout>
        @include('catalogue.partials.detail')
    </x-guest-layout>
@endauth
