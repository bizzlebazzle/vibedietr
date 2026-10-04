@php
    $entryKey = 'entry-'.$entryType.'-'.$entry->id;
    $entryTitle = $entryType === 'recipe' ? ($entry->recipe_snapshot['title'] ?? 'Recipe snapshot') : ($entry->one_off_wording ?? $entry->catalogue_snapshot['name'] ?? 'Catalogue item snapshot');
    $state = $entry->consumptionState;
    $actual = $state?->currentTransition;
    $hasHistory = ($state?->next_sequence ?? 1) > 1;
    $unit = $entryType === 'recipe' ? 'servings' : \App\Domain\Measurements\MeasurementUnitRegistry::definition($entry->planned_unit)->symbol;
    $submitted = old('_planning_form') === $entryKey;
@endphp
<section aria-label="Actions for {{ $entryTitle }}" class="mt-3 space-y-3" data-entry="{{ $entryKey }}">
    <p id="{{ $entryKey }}" tabindex="-1" class="font-semibold" data-entry-state>
        {{ $entryTitle }}: {{ $actual ? 'Consumed' : ($hasHistory ? 'Planned — consumption reversed' : 'Planned — not consumed') }}
    </p>
    @if ($actual)
        <p>Actual: {{ rtrim(rtrim($actual->actual_amount, '0'), '.') }} {{ $unit }} · consumed {{ $actual->consumed_local_at->format('Y-m-d H:i:s') }} ({{ $actual->timezone }}, UTC offset {{ $actual->utc_offset_minutes }} minutes). Diary date: {{ $actual->effective_diary_date->toDateString() }}.</p>
    @endif
    @if ($hasHistory)
        <p class="text-sm">Moving and removal are unavailable because consumption history is retained, including after reversal. Planned quantity stays unchanged.</p>
    @else
        <form method="POST" action="{{ route('meal-plans.'.$entryType.'-entries.update', [$mealPlan, $entry]) }}" class="flex flex-wrap items-end gap-2">
            @csrf
            @method('PATCH')
            <input type="hidden" name="_planning_form" value="{{ $entryKey }}">
            <div class="min-w-0 flex-1">
                <label for="{{ $entryKey }}-destination" class="block">Move {{ $entryTitle }} to day and slot</label>
                <select id="{{ $entryKey }}-destination" name="target_slot_id" class="mt-1 block w-full rounded-md border-gray-300 bg-white text-gray-900 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                    @foreach ($mealPlan->days as $targetDay)
                        @foreach ($targetDay->slots as $targetSlot)
                            <option value="{{ $targetSlot->id }}" @selected(($submitted ? old('target_slot_id', $planSlot->id) : $planSlot->id) == $targetSlot->id)>{{ $targetDay->date?->toDateString() ?? 'Day '.($targetDay->day_index + 1) }} — {{ $targetSlot->name }}</option>
                        @endforeach
                    @endforeach
                </select>
                @if ($submitted)<x-input-error :messages="$errors->get('target_slot_id')" />@endif
            </div>
            <x-primary-button>Move</x-primary-button>
        </form>
        <form method="POST" action="{{ route('meal-plans.'.$entryType.'-entries.destroy', [$mealPlan, $entry]) }}">
            @csrf
            @method('DELETE')
            <x-danger-button :aria-label="'Remove '.$entryTitle">Remove</x-danger-button>
        </form>
    @endif
    @if ($day->date)
        <details @if ($submitted && $errors->any()) open @endif class="rounded border border-gray-300 p-3 dark:border-slate-600">
            <summary class="cursor-pointer font-medium">{{ $actual ? 'Correct consumption' : ($hasHistory ? 'Record consumption again' : 'Record consumption') }} for {{ $entryTitle }}</summary>
            <p id="{{ $entryKey }}-consumption-help" class="mt-2 text-sm">Actual quantity is separate from the planned quantity. {{ $actual ? 'Corrections retain previous consumption history.' : 'First consumption defaults to the planned quantity; after reversal, enter the actual quantity explicitly.' }} A blank time uses now only for today’s diary date; otherwise enter the time. Consumption may occur on the planned date or the following local date; totals stay on the planned diary date. Future times are rejected.</p>
            <form method="POST" action="{{ route('meal-plans.consumption.'.($actual ? 'update' : 'store'), [$mealPlan, $entryType, $entry]) }}" class="mt-3 grid gap-3 sm:grid-cols-2" aria-describedby="{{ $entryKey }}-consumption-help">
                @csrf
                @if ($actual) @method('PATCH') @endif
                <input type="hidden" name="_planning_form" value="{{ $entryKey }}">
                <div>
                    <x-input-label :for="$entryKey.'-amount'" :value="'Actual quantity ('.$unit.')'" />
                    <x-text-input :id="$entryKey.'-amount'" name="actual_amount" type="number" min="{{ $entryType === 'recipe' ? '0.01' : '0.000000000000000001' }}" step="{{ $entryType === 'recipe' ? '0.01' : 'any' }}" :max="$entryType === 'recipe' ? '99999999.99' : null" :required="$hasHistory" :value="$submitted ? old('actual_amount') : ($actual ? rtrim(rtrim($actual->actual_amount, '0'), '.') : ($hasHistory ? '' : ($entryType === 'recipe' ? $entry->planned_servings : $entry->planned_amount)))" class="mt-1 block w-full" />
                    @if ($submitted)<x-input-error :messages="$errors->get('actual_amount')" />@endif
                </div>
                <div>
                    <x-input-label :for="$entryKey.'-time'" value="Consumed local date and time" />
                    <x-text-input :id="$entryKey.'-time'" name="consumed_local_at" type="datetime-local" step="1" :value="$submitted ? old('consumed_local_at') : $actual?->consumed_local_at->format('Y-m-d\TH:i:s')" class="mt-1 block w-full" />
                    @if ($submitted)<x-input-error :messages="$errors->get('consumed_local_at')" />@endif
                </div>
                <div>
                    <x-input-label :for="$entryKey.'-timezone'" value="Consumption timezone (IANA name)" />
                    <x-text-input :id="$entryKey.'-timezone'" name="timezone" :value="$submitted ? old('timezone') : ($actual?->timezone ?? auth()->user()->timezone)" aria-describedby="{{ $entryKey }}-zone-help" class="mt-1 block w-full" />
                    <p id="{{ $entryKey }}-zone-help" class="mt-1 text-xs">Defaults to your account timezone. You may explicitly override it, for example Europe/London or UTC.</p>
                    @if ($submitted)<x-input-error :messages="$errors->get('timezone')" />@endif
                </div>
                <div>
                    <x-input-label :for="$entryKey.'-offset'" value="UTC offset in minutes (optional)" />
                    <x-text-input :id="$entryKey.'-offset'" name="utc_offset_minutes" type="number" min="-840" max="840" step="1" :value="$submitted ? old('utc_offset_minutes') : $actual?->utc_offset_minutes" aria-describedby="{{ $entryKey }}-offset-help" class="mt-1 block w-full" />
                    <p id="{{ $entryKey }}-offset-help" class="mt-1 text-xs">Required when a local time occurs twice at a clock change. Use the intended offset (for example 0 for UTC, 60 for UTC+01:00). Clear or update it when changing time or timezone.</p>
                    @if ($submitted)<x-input-error :messages="$errors->get('utc_offset_minutes')" />@endif
                </div>
                <div class="sm:col-span-2"><x-primary-button>{{ $actual ? 'Save correction' : ($hasHistory ? 'Record again' : 'Mark consumed') }}</x-primary-button></div>
            </form>
        </details>
        @if ($actual)
            <form method="POST" action="{{ route('meal-plans.consumption.destroy', [$mealPlan, $entryType, $entry]) }}" class="space-y-2">
                @csrf
                @method('DELETE')
                <p id="{{ $entryKey }}-reverse-help">Reversal excludes this actual intake from consumed totals and retains its history and planned quantity.</p>
                <x-secondary-button type="submit" aria-describedby="{{ $entryKey }}-reverse-help">Reverse consumption</x-secondary-button>
            </form>
        @endif
    @else
        <p>Consumption is unavailable for reusable undated entries.</p>
    @endif
</section>
