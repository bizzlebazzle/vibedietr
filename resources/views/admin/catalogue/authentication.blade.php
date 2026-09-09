<section class="p-4 rounded border border-gray-300 dark:border-gray-600 space-y-3">
    <h2 class="font-semibold">Authorize your next decision</h2>
    <p><a class="underline" href="{{ route('password.confirm') }}">Confirm your password</a>, then verify a fresh authenticator code. Each successful decision consumes one proof.</p>
    <form method="POST" action="{{ route('security.second-factor.verify') }}" class="flex flex-wrap gap-3 items-end">@csrf
        <input type="hidden" name="operation" value="catalogue-moderation" />
        <div><x-input-label for="moderation-code" value="Six-digit authenticator code" /><x-text-input id="moderation-code" name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="6" /></div>
        <x-primary-button>Verify code</x-primary-button>
    </form>
    <x-input-error :messages="$errors->all()" />
</section>
