<?php

use App\Domain\AccountDeletion\AccountLifecycle;
use App\Domain\AccountDeletion\AuthenticateLifecycle;
use App\Domain\AccountDeletion\JournalUnavailable;
use App\Jobs\ProcessAccountDeletion;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component
{
    public string $password = '';

    public string $code = '';

    public bool $waiveRecovery = false;

    public function deleteUser(Logout $logout, AccountLifecycle $lifecycle, AuthenticateLifecycle $authentication): void
    {
        $this->validate(['password' => ['required', 'string', 'max:1024'], 'code' => ['string', 'max:12'], 'waiveRecovery' => ['boolean']]);
        $user = Auth::user();
        abort_unless($user instanceof \App\Models\User && $user->isActive(), 403);
        $key = 'account-deletion:'.hash_hmac('sha256', (string) $user->getKey(), (string) config('app.key'));
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['password' => 'Too many attempts. Please retry later.']);
        }
        RateLimiter::hit($key, 60);
        $authentication->verify($user, $this->password, $this->code, 'account.delete', (string) request()->ip());
        try {
            $record = $lifecycle->request($user, waive: $this->waiveRecovery);
        } catch (JournalUnavailable) {
            throw ValidationException::withMessages(['password' => 'Lifecycle confirmation is unavailable. Your account is inactive and the original deadline is preserved. Use account recovery to retry.']);
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['password' => 'The sole administrator needs an active replacement before account deletion.']);
        }
        ProcessAccountDeletion::dispatch($record->id, $record->correlation_id);
        $logout();
        $this->redirect(route('account-recovery', absolute: false), navigate: true);
    }
}; ?>

<section class="space-y-6">
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-slate-100">
            {{ __('Delete Account') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600 dark:text-slate-400">
            {{ __('Request account deletion with up to 30 days to recover, or waive recovery and request final purge.') }}
        </p>
    </header>

    <x-danger-button
        id="delete-account-trigger"
        x-data=""
        x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')"
    >{{ __('Delete Account') }}</x-danger-button>

    <x-modal name="confirm-user-deletion" title="Delete account" :show="$errors->isNotEmpty()" focusable>
        <form wire:submit="deleteUser" class="space-y-4">
            <x-validation-summary :errors="$errors" />

            <h2 class="text-lg font-medium text-gray-900 dark:text-slate-100">
                {{ __('Are you sure you want to delete your account?') }}
            </h2>

            <x-account-deletion-notice />

            <div class="mt-6">
                <x-input-label for="password" value="{{ __('Password') }}" class="sr-only" />

                <x-text-input
                    wire:model="password"
                    id="password"
                    name="password"
                    type="password"
                    class="mt-1 block w-full"
                    placeholder="{{ __('Password') }}"
                />

                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>

            <div><x-input-label for="deletion-code" value="Authenticator code (if enabled)" /><x-text-input wire:model="code" id="deletion-code" autocomplete="one-time-code" inputmode="numeric" class="block w-full" /><x-input-error :messages="$errors->get('code')" /></div>
            <label class="flex gap-2 text-sm text-gray-700 dark:text-slate-200"><input type="checkbox" wire:model="waiveRecovery"><span>Waive recovery and request immediate final purge. This cannot be undone.</span></label>

            <div class="mt-6 flex flex-wrap justify-end gap-3">
                <x-secondary-button x-on:click="$dispatch('close')">
                    {{ __('Cancel') }}
                </x-secondary-button>

                <x-danger-button>
                    {{ __('Delete Account') }}
                </x-danger-button>
            </div>
        </form>
    </x-modal>
</section>
