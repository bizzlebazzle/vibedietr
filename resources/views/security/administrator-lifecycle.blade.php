<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200">Administrator lifecycle</h2></x-slot>
    <div class="py-8"><div class="max-w-4xl mx-auto space-y-6 sm:px-6 lg:px-8">
        @if (session('status')) <p role="status" class="p-3 bg-green-100">{{ session('status') }}</p> @endif
        <x-input-error :messages="$errors->get('lifecycle')" />
        <p>Every action requires password confirmation within five minutes and a fresh, operation-specific authenticator code within two minutes.</p>
        <p><a class="underline" href="{{ route('password.confirm') }}">Confirm password</a> · <a class="underline" href="{{ route('security.second-factor.show') }}">Verify an authenticator code</a></p>

        @if (auth()->user()->isAdministrator())
            <section class="p-6 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <h3 class="font-semibold">Initiate promotion</h3>
                <form method="POST" action="{{ route('administrator.lifecycle.promotions.initiate') }}" class="mt-3 space-y-3">@csrf
                    <x-input-label for="target_user_id" value="Target user ID" />
                    <x-text-input id="target_user_id" name="target_user_id" inputmode="numeric" required />
                    <x-primary-button>Initiate promotion</x-primary-button>
                </form>
            </section>
            <section class="p-6 bg-white dark:bg-gray-800 shadow sm:rounded-lg"><h3 class="font-semibold">Pending promotions</h3>
                @forelse ($pending as $promotion)
                    <div class="mt-3">{{ $promotion->target->name }} · expires {{ $promotion->expires_at->utc()->toIso8601String() }}
                        <form class="inline" method="POST" action="{{ route('administrator.lifecycle.promotions.cancel', $promotion) }}">@csrf <x-danger-button>Cancel</x-danger-button></form>
                    </div>
                @empty <p class="mt-2">No pending promotions.</p> @endforelse
            </section>
            <section class="p-6 bg-white dark:bg-gray-800 shadow sm:rounded-lg"><h3 class="font-semibold">Active administrators</h3>
                @foreach ($administrators as $administrator)
                    <div class="mt-3">{{ $administrator->name }}
                        @if (! $administrator->is(auth()->user()))
                            <form class="inline" method="POST" action="{{ route('administrator.lifecycle.revoke', $administrator) }}">@csrf <x-danger-button>Revoke</x-danger-button></form>
                        @endif
                    </div>
                @endforeach
            </section>
        @endif

        <section class="p-6 bg-white dark:bg-gray-800 shadow sm:rounded-lg"><h3 class="font-semibold">Your promotion requests</h3>
            @forelse ($ownPromotions as $promotion)
                <div class="mt-3">{{ ucfirst($promotion->status) }} · expires {{ $promotion->expires_at->utc()->toIso8601String() }}
                    @if ($promotion->status === 'pending')
                        <form class="inline" method="POST" action="{{ route('administrator.lifecycle.promotions.accept', $promotion) }}">@csrf <x-primary-button>Accept</x-primary-button></form>
                        <form class="inline" method="POST" action="{{ route('administrator.lifecycle.promotions.decline', $promotion) }}">@csrf <x-danger-button>Decline</x-danger-button></form>
                    @endif
                </div>
            @empty <p class="mt-2">No promotion requests.</p> @endforelse
        </section>
    </div></div>
</x-app-layout>
