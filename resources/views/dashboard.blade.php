<x-app-layout>
    <x-slot name="header">
        <h1 class="text-2xl font-semibold text-gray-800 dark:text-slate-100">Your dashboard</h1>
    </x-slot>

    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <p class="max-w-2xl text-slate-700 dark:text-slate-300">Pick up where you left off, or start something new.</p>
        <div class="mt-8 grid gap-5 md:grid-cols-2">
            <section class="rounded-lg border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-xl font-semibold">Your recipes</h2>
                <p class="mt-2 text-slate-700 dark:text-slate-300">{{ $recipeCount === 0 ? 'You have no recipes yet.' : 'You have '.$recipeCount.' '.Str::plural('recipe', $recipeCount).'.' }}</p>
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
