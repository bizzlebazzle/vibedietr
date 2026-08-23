@if ($items === [])
    <p class="mt-3 rounded border border-gray-200 bg-white p-5 text-gray-700 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300">{{ $emptyMessage }}</p>
@else
    <div class="mt-3 grid gap-4 sm:grid-cols-2">
        @foreach ($items as $recipe)
            <article class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <h3 class="text-lg font-semibold">
                    <a href="{{ route('recipes.show', $recipe->id) }}" class="text-blue-700 hover:underline dark:text-blue-300">{{ $recipe->title }}</a>
                </h3>
                @if ($recipe->servings !== null)
                    <p class="mt-2 text-sm text-gray-600 dark:text-slate-400">Serves {{ rtrim(rtrim($recipe->servings, '0'), '.') }}</p>
                @endif
                <p class="mt-1 text-sm text-gray-600 dark:text-slate-400">Finalized {{ $recipe->finalizedAt->toFormattedDateString() }}</p>
            </article>
        @endforeach
    </div>
@endif
