<x-app-layout>
    <div class="max-w-xl mx-auto py-8">
        <h1 class="text-xl font-semibold">Confirm your authenticator</h1>
        <p>Scan this QR code or enter the manual key. The secret will not be shown after activation.</p>
        <div aria-label="Authenticator provisioning QR code">{!! $presentation->qrSvg !!}</div>
        <label for="manual-key">Manual setup key</label>
        <input id="manual-key" readonly value="{{ $presentation->manualKey }}" class="w-full" />
        <form method="POST" action="{{ route('security.second-factor.confirm') }}">@csrf
            <x-input-label for="code" value="Six-digit authenticator code" />
            <x-text-input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required />
            <x-input-error :messages="$errors->get('code')" />
            <x-primary-button class="mt-4">Verify code</x-primary-button>
        </form>
        <form method="POST" action="{{ route('security.second-factor.cancel') }}" class="mt-2">@csrf @method('DELETE')<button type="submit">Cancel setup</button></form>
    </div>
</x-app-layout>
