<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800 dark:text-slate-100">{{ $mealPlan->name }}</h2></x-slot>
    <div class="py-12"><div class="mx-auto max-w-3xl sm:px-6 lg:px-8"><div class="rounded-lg bg-white p-6 shadow dark:bg-slate-900">
        <p class="text-gray-700 dark:text-slate-200">{{ $mealPlan->type === \App\Domain\MealPlans\MealPlanType::Reusable ? 'Reusable undated schedule' : 'Dated plan' }}</p>
        @if ($mealPlan->type === \App\Domain\MealPlans\MealPlanType::Dated)
            <p class="mt-2 text-gray-700 dark:text-slate-200">{{ $mealPlan->starts_on->toFormattedDateString() }} – {{ $mealPlan->ends_on->toFormattedDateString() }}</p>
        @endif
        <p class="mt-2 text-sm text-gray-600 dark:text-slate-300">Private</p>
        <a href="{{ route('meal-plans.edit', $mealPlan) }}" class="mt-6 inline-block text-indigo-700 underline dark:text-indigo-300">Edit meal plan</a>

        <section class="mt-8 border-t border-gray-200 pt-6 dark:border-slate-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Plan days</h3>
            <x-input-error :messages="$errors->all()" class="mt-2" />

            <form method="POST" action="{{ route('meal-plans.days.store', $mealPlan) }}" class="mt-4 flex flex-wrap items-end gap-3">
                @csrf
                @if ($mealPlan->type === \App\Domain\MealPlans\MealPlanType::Reusable)
                    <div>
                        <x-input-label for="day_index" value="Day index" />
                        <x-text-input id="day_index" name="day_index" type="number" min="0" required
                            :value="old('day_index', ((int) ($mealPlan->days->max('day_index') ?? -1)) + 1)" />
                    </div>
                @else
                    <div>
                        <x-input-label for="date" value="Date" />
                        <x-text-input id="date" name="date" type="date" required
                            :min="$mealPlan->starts_on->toDateString()" :max="$mealPlan->ends_on->toDateString()" :value="old('date')" />
                    </div>
                @endif
                <x-primary-button>Add day</x-primary-button>
            </form>

            <div class="mt-6 space-y-6">
                @forelse ($mealPlan->days as $day)
                    <article class="rounded-md border border-gray-200 p-4 dark:border-slate-700">
                        <h4 class="font-semibold text-gray-900 dark:text-slate-100">
                            {{ $day->date?->toFormattedDateString() ?? 'Day '.($day->day_index + 1) }}
                        </h4>
                        <div class="mt-3 space-y-3">
                            @foreach ($day->slots as $planSlot)
                                <div class="flex items-center gap-3">
                                    <span class="w-8 text-sm text-gray-500 dark:text-slate-400">{{ $planSlot->position + 1 }}.</span>
                                    @if ($planSlot->standard_key?->hasFixedName())
                                        <span class="text-gray-800 dark:text-slate-200">{{ $planSlot->name }}</span>
                                    @else
                                        <form method="POST" action="{{ route('meal-plans.days.slots.update', [$mealPlan, $day, $planSlot]) }}" class="flex flex-1 gap-2">
                                            @csrf
                                            @method('PATCH')
                                            <x-text-input name="name" :value="$planSlot->name" required maxlength="255" class="flex-1" />
                                            <x-primary-button>Rename</x-primary-button>
                                        </form>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        <form method="POST" action="{{ route('meal-plans.days.slots.store', [$mealPlan, $day]) }}" class="mt-4 flex gap-2">
                            @csrf
                            <x-text-input name="name" placeholder="Extra slot name" required maxlength="255" class="flex-1" />
                            <x-primary-button>Add slot</x-primary-button>
                        </form>

                        <form method="POST" action="{{ route('meal-plans.days.slots.reorder', [$mealPlan, $day]) }}" class="mt-4 grid gap-2 sm:grid-cols-2">
                            @csrf
                            @method('PUT')
                            @foreach ($day->slots as $position => $planSlot)
                                <label class="text-sm text-gray-700 dark:text-slate-300">
                                    Position {{ $position + 1 }}
                                    <select name="slot_ids[]" class="mt-1 block w-full rounded-md border-gray-300 bg-white text-gray-900 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                                        @foreach ($day->slots as $option)
                                            <option value="{{ $option->id }}" @selected($option->is($planSlot))>{{ $option->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            @endforeach
                            <div class="sm:col-span-2"><x-primary-button>Save slot order</x-primary-button></div>
                        </form>
                    </article>
                @empty
                    <p class="text-sm text-gray-600 dark:text-slate-300">No days added yet.</p>
                @endforelse
            </div>
        </section>
    </div></div></div>
</x-app-layout>
