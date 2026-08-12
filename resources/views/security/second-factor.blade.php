<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Two-step verification</h2></x-slot>
    <div class="max-w-xl mx-auto py-8">
        @if (session('status')) <p role="status">{{ session('status') }}</p> @endif
        @if ($enrolled)
            <p>Your authenticator factor is confirmed.</p>
        @else
            <p>Set up a standards-compatible authenticator. Enrollment does not grant administrator access.</p>
            <form method="POST" action="{{ route('security.second-factor.begin') }}">@csrf
                <x-input-label for="password" value="Confirm your password now" />
                <x-text-input id="password" name="password" type="password" autocomplete="current-password" required />
                <x-input-error :messages="$errors->get('password')" />
                <x-primary-button class="mt-4">Begin setup</x-primary-button>
            </form>
        @endif
    </div>
</x-app-layout>
