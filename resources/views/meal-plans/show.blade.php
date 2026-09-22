<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800 dark:text-slate-100">{{ $mealPlan->name }}</h2></x-slot>
    <div class="py-12"><div class="mx-auto max-w-3xl sm:px-6 lg:px-8"><div class="rounded-lg bg-white p-6 shadow dark:bg-slate-900">
        <p class="text-gray-700 dark:text-slate-200">{{ $mealPlan->type === \App\Domain\MealPlans\MealPlanType::Reusable ? 'Reusable undated schedule' : 'Dated plan' }}</p>
        @if ($mealPlan->type === \App\Domain\MealPlans\MealPlanType::Dated)
            <p class="mt-2 text-gray-700 dark:text-slate-200">{{ $mealPlan->starts_on->toFormattedDateString() }} – {{ $mealPlan->ends_on->toFormattedDateString() }}</p>
        @endif
        <p class="mt-2 text-sm text-gray-600 dark:text-slate-300">{{ $mealPlan->visibility === \App\Domain\MealPlans\MealPlanVisibility::Public ? 'Public read-only sharing is active' : 'Private' }}</p>
        <a href="{{ route('meal-plans.edit', $mealPlan) }}" class="mt-6 inline-block text-indigo-700 underline dark:text-indigo-300">Edit meal plan</a>

        <section class="mt-8 border-t border-gray-200 pt-6 dark:border-slate-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Sharing</h3>
            <p class="mt-2 text-sm text-gray-600 dark:text-slate-300">Viewers are read-only. A share never grants edit or reshare rights.</p>
            <x-input-error :messages="$errors->all()" class="mt-2" />

            @if ($mealPlan->visibility === \App\Domain\MealPlans\MealPlanVisibility::Public)
                <form method="POST" action="{{ route('meal-plans.public.destroy', $mealPlan) }}" class="mt-4">
                    @csrf
                    @method('DELETE')
                    <x-secondary-button>Make private</x-secondary-button>
                </form>
            @else
                <form method="POST" action="{{ route('meal-plans.public.store', $mealPlan) }}" class="mt-4">
                    @csrf
                    <x-primary-button>Share publicly</x-primary-button>
                </form>
                <p class="mt-2 text-xs text-gray-600 dark:text-slate-400">Public sharing is rejected unless the complete presented plan and every exposed pinned snapshot are proven public-safe.</p>

                <form method="POST" action="{{ route('meal-plans.shares.store', $mealPlan) }}" class="mt-6 space-y-3 rounded border border-gray-200 p-4 dark:border-slate-700">
                    @csrf
                    <div>
                        <x-input-label for="recipient_email" value="Selected registered user's email" />
                        <x-text-input id="recipient_email" name="recipient_email" type="email" required maxlength="255" class="mt-1 block w-full" :value="old('recipient_email')" />
                    </div>
                    <label class="flex items-start gap-2 text-sm text-gray-700 dark:text-slate-300">
                        <input type="checkbox" name="acknowledge_private_recipe_snapshots" value="1" @checked(old('acknowledge_private_recipe_snapshots')) class="mt-1 rounded border-gray-300">
                        <span>I acknowledge that this read-only share may expose the private pinned recipe snapshots needed to understand this plan, without granting access to the live private recipes.</span>
                    </label>
                    <x-primary-button>Grant read-only access</x-primary-button>
                </form>
            @endif

            <div class="mt-5 space-y-2">
                @forelse ($mealPlan->shares as $share)
                    <div class="flex items-center justify-between gap-3 rounded border border-gray-200 p-3 text-sm dark:border-slate-700">
                        <span class="text-gray-700 dark:text-slate-300">Selected-user share {{ $loop->iteration }} · {{ $share->private_recipe_snapshots_acknowledged_at ? 'private snapshot access acknowledged' : 'public snapshots only' }}</span>
                        <form method="POST" action="{{ route('meal-plans.shares.destroy', [$mealPlan, $share]) }}">
                            @csrf
                            @method('DELETE')
                            <x-danger-button>Revoke</x-danger-button>
                        </form>
                    </div>
                @empty
                    <p class="text-sm text-gray-600 dark:text-slate-300">No selected-user shares.</p>
                @endforelse
            </div>
        </section>

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
                                <div class="rounded-md border border-gray-100 p-3 dark:border-slate-800">
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

                                    <div class="ml-11 mt-3 space-y-3">
                                        @foreach ($planSlot->itemEntries as $entry)
                                            <div class="rounded bg-gray-50 p-3 text-sm text-gray-800 dark:bg-slate-800 dark:text-slate-200">
                                                <p class="font-medium">
                                                    {{ $entry->kind === \App\Domain\MealPlans\MealPlanItemEntryKind::Catalogue
                                                        ? ($entry->catalogue_snapshot['name'] ?? 'Catalogue item snapshot')
                                                        : $entry->one_off_wording }}
                                                </p>
                                                <p>
                                                    {{ rtrim(rtrim($entry->planned_amount, '0'), '.') }}
                                                    {{ \App\Domain\Measurements\MeasurementUnitRegistry::definition($entry->planned_unit)->symbol }} planned
                                                    · {{ $entry->kind === \App\Domain\MealPlans\MealPlanItemEntryKind::Catalogue ? 'catalogue version '.$entry->catalogue_item_version_number : 'private one-off item' }}
                                                </p>
                                                <div class="mt-2 flex flex-wrap gap-2">
                                                    <form method="POST" action="{{ route('meal-plans.item-entries.update', [$mealPlan, $entry]) }}" class="flex items-end gap-2">
                                                        @csrf
                                                        @method('PATCH')
                                                        <label>
                                                            <span class="block text-xs">Move to</span>
                                                            <select name="target_slot_id" class="mt-1 rounded-md border-gray-300 bg-white text-gray-900 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                                                                @foreach ($mealPlan->days as $targetDay)
                                                                    @foreach ($targetDay->slots as $targetSlot)
                                                                        <option value="{{ $targetSlot->id }}" @selected($targetSlot->is($planSlot))>
                                                                            {{ $targetDay->date?->toDateString() ?? 'Day '.($targetDay->day_index + 1) }} — {{ $targetSlot->name }}
                                                                        </option>
                                                                    @endforeach
                                                                @endforeach
                                                            </select>
                                                        </label>
                                                        <x-primary-button>Move</x-primary-button>
                                                    </form>
                                                    <form method="POST" action="{{ route('meal-plans.item-entries.destroy', [$mealPlan, $entry]) }}">
                                                        @csrf
                                                        @method('DELETE')
                                                        <x-danger-button>Remove</x-danger-button>
                                                    </form>
                                                </div>
                                            </div>
                                        @endforeach

                                        @foreach ($planSlot->recipeEntries as $entry)
                                            <div class="rounded bg-gray-50 p-3 text-sm text-gray-800 dark:bg-slate-800 dark:text-slate-200">
                                                <p class="font-medium">{{ $entry->recipe_snapshot['title'] ?? 'Recipe snapshot' }}</p>
                                                <p>{{ $entry->planned_servings }} planned servings · version {{ $entry->recipe_version_number }}</p>
                                                @foreach ($entry->versionReviews as $review)
                                                    <div class="mt-3 rounded border border-amber-300 bg-amber-50 p-3 text-amber-950 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
                                                        <p>A newer recipe version (version {{ $review->recipeVersion->version_number }}) is available. Your pinned snapshot has not changed.</p>
                                                        <div class="mt-2 flex flex-wrap gap-2">
                                                            <form method="POST" action="{{ route('meal-plans.recipe-version-reviews.update', [$mealPlan, $review]) }}">
                                                                @csrf
                                                                <x-primary-button>Update to version {{ $review->recipeVersion->version_number }}</x-primary-button>
                                                            </form>
                                                            <form method="POST" action="{{ route('meal-plans.recipe-version-reviews.retain', [$mealPlan, $review]) }}">
                                                                @csrf
                                                                <x-secondary-button>Retain version {{ $entry->recipe_version_number }}</x-secondary-button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                @endforeach
                                                <div class="mt-2 flex flex-wrap gap-2">
                                                    <form method="POST" action="{{ route('meal-plans.recipe-entries.update', [$mealPlan, $entry]) }}" class="flex items-end gap-2">
                                                        @csrf
                                                        @method('PATCH')
                                                        <label>
                                                            <span class="block text-xs">Move to</span>
                                                            <select name="target_slot_id" class="mt-1 rounded-md border-gray-300 bg-white text-gray-900 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                                                                @foreach ($mealPlan->days as $targetDay)
                                                                    @foreach ($targetDay->slots as $targetSlot)
                                                                        <option value="{{ $targetSlot->id }}" @selected($targetSlot->is($planSlot))>
                                                                            {{ $targetDay->date?->toDateString() ?? 'Day '.($targetDay->day_index + 1) }} — {{ $targetSlot->name }}
                                                                        </option>
                                                                    @endforeach
                                                                @endforeach
                                                            </select>
                                                        </label>
                                                        <x-primary-button>Move</x-primary-button>
                                                    </form>
                                                    <form method="POST" action="{{ route('meal-plans.recipe-entries.destroy', [$mealPlan, $entry]) }}">
                                                        @csrf
                                                        @method('DELETE')
                                                        <x-danger-button>Remove</x-danger-button>
                                                    </form>
                                                </div>
                                            </div>
                                        @endforeach

                                        <form method="POST" action="{{ route('meal-plans.recipe-entries.store', $mealPlan) }}" class="flex flex-wrap items-end gap-2">
                                            @csrf
                                            <input type="hidden" name="slot_id" value="{{ $planSlot->id }}">
                                            <label class="text-sm text-gray-700 dark:text-slate-300">
                                                Recipe ID
                                                <x-text-input name="recipe_id" type="number" min="1" required class="mt-1 block w-32" />
                                            </label>
                                            <label class="text-sm text-gray-700 dark:text-slate-300">
                                                Planned servings
                                                <x-text-input name="planned_servings" type="number" min="0.01" max="99999999.99" step="0.01" required class="mt-1 block w-36" />
                                            </label>
                                            <x-primary-button>Add recipe</x-primary-button>
                                        </form>

                                        <form method="POST" action="{{ route('meal-plans.item-entries.store', $mealPlan) }}" class="flex flex-wrap items-end gap-2">
                                            @csrf
                                            <input type="hidden" name="slot_id" value="{{ $planSlot->id }}">
                                            <input type="hidden" name="kind" value="catalogue">
                                            <label class="text-sm text-gray-700 dark:text-slate-300">
                                                Approved catalogue item ID
                                                <x-text-input name="catalogue_item_id" type="number" min="1" required class="mt-1 block w-36" />
                                            </label>
                                            <label class="text-sm text-gray-700 dark:text-slate-300">
                                                Planned amount
                                                <x-text-input name="planned_amount" type="number" min="0.000000000000000001" step="any" required class="mt-1 block w-36" />
                                            </label>
                                            <label class="text-sm text-gray-700 dark:text-slate-300">
                                                Unit
                                                <select name="planned_unit" required class="mt-1 block rounded-md border-gray-300 bg-white text-gray-900 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                                                    @foreach (\App\Domain\Measurements\MeasurementUnitRegistry::formGroups() as $group => $units)
                                                        <optgroup label="{{ $group }}">
                                                            @foreach ($units as $symbol => $label)
                                                                <option value="{{ \App\Domain\Measurements\MeasurementUnitRegistry::findStandard($symbol)?->value }}">{{ $label }}</option>
                                                            @endforeach
                                                        </optgroup>
                                                    @endforeach
                                                </select>
                                            </label>
                                            <x-primary-button>Add catalogue item</x-primary-button>
                                        </form>

                                        <details class="rounded border border-gray-200 p-3 dark:border-slate-700">
                                            <summary class="cursor-pointer text-sm font-medium text-gray-800 dark:text-slate-200">Add a private one-off item</summary>
                                            <form method="POST" action="{{ route('meal-plans.item-entries.store', $mealPlan) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                                                @csrf
                                                <input type="hidden" name="slot_id" value="{{ $planSlot->id }}">
                                                <input type="hidden" name="kind" value="one_off">
                                                <label class="text-sm text-gray-700 dark:text-slate-300 sm:col-span-2">
                                                    Item wording
                                                    <x-text-input name="one_off_wording" required maxlength="255" class="mt-1 block w-full" />
                                                </label>
                                                <label class="text-sm text-gray-700 dark:text-slate-300">
                                                    Planned amount
                                                    <x-text-input name="planned_amount" type="number" min="0.000000000000000001" step="any" required class="mt-1 block w-full" />
                                                </label>
                                                <label class="text-sm text-gray-700 dark:text-slate-300">
                                                    Unit
                                                    <select name="planned_unit" required class="mt-1 block w-full rounded-md border-gray-300 bg-white text-gray-900 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                                                        @foreach (\App\Domain\Measurements\MeasurementUnitRegistry::formGroups() as $group => $units)
                                                            <optgroup label="{{ $group }}">
                                                                @foreach ($units as $symbol => $label)
                                                                    <option value="{{ \App\Domain\Measurements\MeasurementUnitRegistry::findStandard($symbol)?->value }}">{{ $label }}</option>
                                                                @endforeach
                                                            </optgroup>
                                                        @endforeach
                                                    </select>
                                                </label>
                                                <label class="text-sm text-gray-700 dark:text-slate-300 sm:col-span-2">
                                                    Nutrition basis (required when nutrition is entered)
                                                    <select name="one_off_nutrition_basis" class="mt-1 block w-full rounded-md border-gray-300 bg-white text-gray-900 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                                                        <option value="">No nutrition entered</option>
                                                        <option value="per_100g">Per 100 g</option>
                                                        <option value="per_100ml">Per 100 ml</option>
                                                        <option value="per_serving">Per serving</option>
                                                        <option value="per_item">Per item</option>
                                                    </select>
                                                </label>
                                                @foreach (\App\Domain\Nutrition\NutrientRegistry::all() as $definition)
                                                    <label class="text-sm text-gray-700 dark:text-slate-300">
                                                        {{ $definition->label }} ({{ $definition->preferredDisplayUnit->symbol() }})
                                                        <x-text-input name="one_off_nutrition[{{ $definition->id->value }}]" type="number" min="0" step="any" class="mt-1 block w-full" />
                                                    </label>
                                                @endforeach
                                                <p class="text-xs text-gray-600 dark:text-slate-300 sm:col-span-2">This wording and nutrition stay private on this plan entry. Adding it does not submit anything to the shared catalogue.</p>
                                                <div class="sm:col-span-2"><x-primary-button>Add one-off item</x-primary-button></div>
                                            </form>
                                        </details>
                                    </div>
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
