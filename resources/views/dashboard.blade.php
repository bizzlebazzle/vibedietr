<x-app-layout>
    <x-slot name="header">
        <h1 class="text-2xl font-semibold text-gray-800 dark:text-slate-100">Your dashboard</h1>
    </x-slot>

    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <p class="max-w-2xl text-slate-700 dark:text-slate-300">Pick up where you left off, or start something new.</p>
        @if ($recipeCount === 0 && $planCount === 0)
            <section aria-labelledby="getting-started-heading" class="mt-6 rounded-lg border border-blue-200 bg-blue-50 p-6 dark:border-blue-900 dark:bg-blue-950/30">
                <h2 id="getting-started-heading" class="text-xl font-semibold">Get started with VibeDietr</h2>
                <ol class="mt-3 list-decimal space-y-3 pl-5 text-sm">
                    <li>Create a recipe or import pasted text, a public webpage, or a supported document or photo. Imports start as private drafts that need review.</li>
                    <li>Save your ingredient lines, then search the food catalogue to select matches. Review excluded ingredients and quantities to improve incomplete nutrition estimates; missing values are not zero.</li>
                    <li>Create a reusable or dated meal plan, add a day, then add a finalized recipe to a slot with planned servings. Draft recipes cannot be planned.</li>
                </ol>
                <p class="mt-3 text-sm">Nutrition calculations are estimates, not verified facts or medical advice.</p>
            </section>
        @endif
        <div class="mt-8 grid gap-5 md:grid-cols-2">
            <section class="rounded-lg border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-xl font-semibold">Your recipes</h2>
                <p class="mt-2 text-slate-700 dark:text-slate-300">{{ $recipeCount === 0 ? 'You have no recipes yet.' : 'You have '.$recipeCount.' '.Str::plural('recipe', $recipeCount).'.' }}</p>
                @if ($recipeCount === 0)<p class="mt-2 text-sm">Start with a title and save a private draft, then add ingredients and instructions. Review imports before finalizing.</p>@endif
                <div class="mt-5 flex flex-wrap gap-4 font-medium text-sky-700 dark:text-sky-300">
                    <a class="underline" href="{{ route('recipes.create') }}">Create a recipe</a>
                    <a class="underline" href="{{ route('recipe-imports.create') }}">Import a recipe</a>
                    <a class="underline" href="{{ route('recipes.index') }}">Discover recipes</a>
                    <a class="underline" href="{{ route('bookmarks.index') }}">Bookmarks</a>
                    <a class="underline" href="{{ route('recipe-collections.index') }}">Collections</a>
                    <a class="underline" href="{{ route('private-recipe-tags.index') }}">Private tags</a>
                </div>
            </section>
            <section class="rounded-lg border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-xl font-semibold">Your meal plans</h2>
                <p class="mt-2 text-slate-700 dark:text-slate-300">{{ $planCount === 0 ? 'You have no meal plans yet.' : 'You have '.$planCount.' '.Str::plural('meal plan', $planCount).'.' }}</p>
                @if ($planCount === 0)<p class="mt-2 text-sm">Choose reusable days or a date range. Add a day to get meal slots, then add finalized recipes, catalogue foods, or private one-off items.</p>@endif
                <div class="mt-5 flex flex-wrap gap-4 font-medium text-sky-700 dark:text-sky-300">
                    <a class="underline" href="{{ route('meal-plans.create') }}">Create a meal plan</a>
                    <a class="underline" href="{{ route('meal-plans.index') }}">View meal plans</a>
                </div>
            </section>
        </div>
        <section class="mt-5 rounded-lg border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
            <h2 class="text-xl font-semibold">Explore foods</h2>
            <p class="mt-2 text-slate-700 dark:text-slate-300">Browse the shared catalogue for food and product nutrition. Recipe calculations are estimates.</p>
            <a class="mt-4 inline-block font-medium text-sky-700 underline dark:text-sky-300" href="{{ route('catalogue.index') }}">Browse the food catalogue</a>
        </section>
    </div>
</x-app-layout>
