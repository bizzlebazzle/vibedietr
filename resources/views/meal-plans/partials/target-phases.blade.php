<section class="mt-8 border-t border-gray-200 pt-6 dark:border-slate-700">
    <h3 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Nutrition target phases</h3>
    <p class="mt-2 text-sm text-gray-600 dark:text-slate-300">Assign one of your named target profiles to plan dates. Dates without a phase have no target. Changes made today apply from tomorrow; explicitly filled past dates keep the values selected now.</p>

    <form method="POST" action="{{ route('meal-plans.target-phases.store', $mealPlan) }}" class="mt-4 grid gap-3 rounded border border-gray-200 p-4 dark:border-slate-700 sm:grid-cols-2">
        @csrf
        <div>
            <x-input-label for="phase_profile_id" value="Target profile" />
            <select id="phase_profile_id" name="profile_id" required class="mt-1 block w-full rounded-md border-gray-300 bg-white text-gray-900 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                @foreach ($targetProfiles as $profile)
                    <option value="{{ $profile->id }}" @selected(old('profile_id') == $profile->id)>{{ $profile->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="phase_starts_on" value="Start date" />
            <x-text-input id="phase_starts_on" name="starts_on" type="date" required :min="$mealPlan->starts_on->toDateString()" :max="$mealPlan->ends_on->toDateString()" :value="old('starts_on')" class="mt-1 block w-full" />
        </div>
        <div>
            <x-input-label for="phase_ends_on" value="End date (optional)" />
            <x-text-input id="phase_ends_on" name="ends_on" type="date" :min="$mealPlan->starts_on->toDateString()" :max="$mealPlan->ends_on->toDateString()" :value="old('ends_on')" class="mt-1 block w-full" />
        </div>
        <div class="flex items-end"><x-primary-button>Assign phase</x-primary-button></div>
    </form>

    <div class="mt-5 space-y-3">
        @forelse ($mealPlan->targetPhases as $phase)
            <div class="rounded border border-gray-200 p-3 text-sm dark:border-slate-700">
                <p class="text-gray-800 dark:text-slate-200">{{ $phase->profile_name_snapshot }} · {{ $phase->starts_on->toDateString() }} – {{ $phase->ends_on?->toDateString() ?? 'open-ended' }}</p>
                @if ($phase->ends_on === null || $phase->ends_on->toDateString() > now(auth()->user()->timezone)->toDateString())
                    @if ($phase->nutrition_target_profile_id !== null)
                        <form method="POST" action="{{ route('meal-plans.target-phases.update', [$mealPlan, $phase]) }}" class="mt-3 flex flex-wrap items-end gap-2">
                            @csrf
                            @method('PATCH')
                            <label class="text-gray-700 dark:text-slate-300">Profile
                                <select name="profile_id" class="block rounded border-gray-300 bg-white dark:border-slate-700 dark:bg-slate-900">
                                    @foreach ($targetProfiles as $profile)
                                        <option value="{{ $profile->id }}" @selected($phase->nutrition_target_profile_id === $profile->id)>{{ $profile->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="text-gray-700 dark:text-slate-300">Start <x-text-input name="starts_on" type="date" required :value="$phase->starts_on->toDateString()" /></label>
                            <label class="text-gray-700 dark:text-slate-300">End <x-text-input name="ends_on" type="date" :value="$phase->ends_on?->toDateString()" /></label>
                            <x-secondary-button>Change future dates</x-secondary-button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('meal-plans.target-phases.destroy', [$mealPlan, $phase]) }}" class="mt-2">
                        @csrf
                        @method('DELETE')
                        <x-danger-button>Remove future dates</x-danger-button>
                    </form>
                @else
                    <p class="mt-1 text-gray-500 dark:text-slate-400">Historical target values retained</p>
                @endif
            </div>
        @empty
            <p class="text-sm text-gray-600 dark:text-slate-300">No target phases assigned.</p>
        @endforelse
    </div>
</section>
