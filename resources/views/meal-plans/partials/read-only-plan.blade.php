<div class="rounded-lg bg-white p-6 shadow dark:bg-slate-900">
    @if ($mealPlan->visibility === \App\Domain\MealPlans\MealPlanVisibility::RetainedUnlisted)
        <div class="rounded border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
            <p class="font-semibold">Former VibeDietr user</p>
            <p class="mt-1">This unlisted public plan remains available because it is bookmarked. It cannot receive new bookmarks. Recipe details are preserved snapshots; links to current recipes may be unavailable.</p>
        </div>
    @endif

    <p class="text-gray-700 dark:text-slate-200">{{ $mealPlan->type === \App\Domain\MealPlans\MealPlanType::Reusable ? 'Reusable undated schedule' : 'Dated plan' }}</p>
    @if ($mealPlan->type === \App\Domain\MealPlans\MealPlanType::Dated)
        <p class="mt-2 text-gray-700 dark:text-slate-200">{{ $mealPlan->starts_on->toFormattedDateString() }} – {{ $mealPlan->ends_on->toFormattedDateString() }}</p>
    @endif
    <p class="mt-2 text-sm text-gray-600 dark:text-slate-300">Read-only {{ $mealPlan->isPubliclyAccessible() ? 'public plan' : 'plan shared with you' }}</p>

    @auth
        <form method="POST" action="{{ route('meal-plans.copy', $mealPlan) }}" class="mt-4">
            @csrf
            <x-primary-button>Copy to my meal plans</x-primary-button>
        </form>

        @if ($bookmark)
            <form method="POST" action="{{ route('meal-plans.bookmarks.destroy', $bookmark) }}" class="mt-4">
                @csrf
                @method('DELETE')
                <x-secondary-button>Remove private bookmark</x-secondary-button>
            </form>
        @elseif ($mealPlan->isBookmarkableBy(auth()->user()))
            <form method="POST" action="{{ route('meal-plans.bookmarks.store', $mealPlan) }}" class="mt-4">
                @csrf
                <x-primary-button>Bookmark privately</x-primary-button>
            </form>
        @endif
    @endauth

    <section class="mt-8 border-t border-gray-200 pt-6 dark:border-slate-700">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Plan days</h2>
        <div class="mt-6 space-y-6">
            @forelse ($mealPlan->days as $day)
                <article class="rounded-md border border-gray-200 p-4 dark:border-slate-700">
                    <h3 class="font-semibold text-gray-900 dark:text-slate-100">{{ $day->date?->toFormattedDateString() ?? 'Day '.($day->day_index + 1) }}</h3>
                    <div class="mt-3 space-y-3">
                        @foreach ($day->slots as $planSlot)
                            <div class="rounded-md border border-gray-100 p-3 dark:border-slate-800">
                                <h4 class="font-medium text-gray-800 dark:text-slate-200">{{ $planSlot->name }}</h4>
                                <div class="mt-2 space-y-2">
                                    @foreach ($planSlot->recipeEntries as $entry)
                                        <div class="rounded bg-gray-50 p-3 text-sm text-gray-800 dark:bg-slate-800 dark:text-slate-200">
                                            <p class="font-medium">{{ $entry->recipe_snapshot['title'] ?? 'Recipe snapshot' }}</p>
                                            <p>{{ $entry->planned_servings }} planned servings · pinned version {{ $entry->recipe_version_number }}</p>
                                        </div>
                                    @endforeach
                                    @foreach ($planSlot->itemEntries as $entry)
                                        <div class="rounded bg-gray-50 p-3 text-sm text-gray-800 dark:bg-slate-800 dark:text-slate-200">
                                            <p class="font-medium">{{ $entry->kind === \App\Domain\MealPlans\MealPlanItemEntryKind::Catalogue ? ($entry->catalogue_snapshot['name'] ?? 'Catalogue item snapshot') : $entry->one_off_wording }}</p>
                                            <p>{{ rtrim(rtrim($entry->planned_amount, '0'), '.') }} {{ \App\Domain\Measurements\MeasurementUnitRegistry::definition($entry->planned_unit)->symbol }} planned</p>
                                        </div>
                                    @endforeach
                                    @if ($planSlot->recipeEntries->isEmpty() && $planSlot->itemEntries->isEmpty())
                                        <p class="text-sm text-gray-500 dark:text-slate-400">No planned entries.</p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </article>
            @empty
                <p class="text-sm text-gray-600 dark:text-slate-300">No days added yet.</p>
            @endforelse
        </div>
    </section>
</div>
