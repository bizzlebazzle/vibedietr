@auth
    <x-app-layout>
        <x-slot name="header"><h1 class="text-xl font-semibold text-gray-800 dark:text-slate-100">{{ $mealPlan->name }}</h1></x-slot>
        <div class="planning-content py-8">
            <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                @include('meal-plans.partials.read-only-plan')
                @if ($mealPlan->visibility->value === 'retained_unlisted' && auth()->user()?->isAdministrator())
                    <details class="mt-6 rounded-lg border border-gray-300 p-4 dark:border-slate-600 dark:text-slate-200">
                        <summary>Exceptional administrator removal</summary>
                        <p class="my-3 text-sm">For privacy, security, legal or moderation reasons only. Suppression stops access; permanent removal cannot be undone. These actions cannot restore, transfer or reattribute a plan or extend recovery.</p>
                        <form method="POST" action="{{ route('admin.retained-plans.moderate', $mealPlan) }}" class="space-y-3">
                            @csrf
                            <x-validation-summary :errors="$errors" />
                            <div><x-input-label for="retained-action" value="Action" /><select id="retained-action" name="action" class="dark:bg-slate-900"><option value="suppress">Suppress access</option><option value="remove">Permanently remove</option></select></div>
                            <div><x-input-label for="retained-reason" value="Reason" /><select id="retained-reason" name="reason" class="dark:bg-slate-900">@foreach (['privacy', 'security', 'legal', 'moderation'] as $reason)<option>{{ $reason }}</option>@endforeach</select></div>
                            <div><x-input-label for="retained-password" value="Confirm password" /><x-text-input id="retained-password" name="password" type="password" autocomplete="current-password" required /></div>
                            <div><x-input-label for="retained-code" value="Authenticator code" /><x-text-input id="retained-code" name="code" autocomplete="one-time-code" inputmode="numeric" required /></div>
                            <x-danger-button>Confirm exceptional action</x-danger-button>
                        </form>
                    </details>
                @endif
            </div>
        </div>
    </x-app-layout>
@else
    <x-guest-layout>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-slate-100">{{ $mealPlan->name }}</h1>
        <div class="mt-6">
            @include('meal-plans.partials.read-only-plan')
        </div>
    </x-guest-layout>
@endauth
