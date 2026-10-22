<x-guest-layout>
    <h1 class="text-xl font-semibold text-gray-900 dark:text-slate-100">Account recovery</h1>
    <p class="mt-3 text-sm text-gray-600 dark:text-slate-300">Sign in here to recover an inactive account before its original 30-day deadline, or waive recovery and request final purge. Ordinary login and data processing remain restricted while inactive. A waiver cannot be undone.</p>
    <p class="mt-3 text-sm text-gray-600 dark:text-slate-300">Recovery restores ownership, attribution and previous plan visibility. Permanently removed public plans cannot return; their private consumption history remains in your account export. Confirmed under-13 accounts cannot be recovered.</p>
    <x-auth-session-status class="my-4" :status="session('status')" />
    <x-validation-summary :errors="$errors" />
    <form method="POST" action="{{ route('account-recovery.store') }}" class="mt-5 space-y-4">
        @csrf
        <div><x-input-label for="recovery-email" value="Email" /><x-text-input id="recovery-email" name="email" type="email" autocomplete="username" class="block w-full" :value="old('email')" required /></div>
        <div><x-input-label for="recovery-password" value="Password" /><x-text-input id="recovery-password" name="password" type="password" autocomplete="current-password" class="block w-full" required /></div>
        <div><x-input-label for="recovery-code" value="Authenticator code (if enabled)" /><x-text-input id="recovery-code" name="code" inputmode="numeric" autocomplete="one-time-code" class="block w-full" /></div>
        <div><x-input-label for="recovery-operation" value="Action" /><select id="recovery-operation" name="operation" class="block w-full rounded-md border-gray-300 dark:bg-slate-900 dark:text-slate-100"><option value="recover">Recover my account</option><option value="waive" @selected(old('operation') === 'waive')>Waive recovery and request final purge</option></select></div>
        <label class="flex gap-2 text-sm text-gray-700 dark:text-slate-200"><input type="checkbox" name="confirmed" value="1" required><span>I understand the consequences of my selected action.</span></label>
        <x-primary-button>Confirm action</x-primary-button>
    </form>
    <a href="{{ route('login') }}" class="mt-5 inline-block text-sm underline dark:text-slate-200">Back to login</a>
</x-guest-layout>
