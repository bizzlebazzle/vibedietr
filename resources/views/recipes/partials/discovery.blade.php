<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold text-gray-900 dark:text-slate-100">Discover recipes</h1>
        <p class="mt-1 text-sm text-gray-600 dark:text-slate-400">Browse current public recipes.</p>
    </div>

    <form method="GET" action="{{ route('recipes.index') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
        <div class="min-w-0 flex-1">
            <label for="recipe-search" class="block text-sm font-medium">Search recipes</label>
            <input
                id="recipe-search"
                name="q"
                type="search"
                maxlength="100"
                value="{{ $search }}"
                placeholder="Title, tag, or classification"
                class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 dark:bg-slate-800"
            >
        </div>
        <button type="submit" class="rounded bg-blue-600 px-4 py-2 font-semibold text-white">Search</button>
        @if ($search !== '')
            <a href="{{ route('recipes.index') }}" class="rounded border px-4 py-2 text-center font-semibold dark:border-slate-600">Clear</a>
        @endif
    </form>

    @if ($recipes->isEmpty())
        <div class="rounded border border-gray-200 bg-white p-6 text-gray-700 shadow-sm dark:border-slate-800 dark:bg-slate-900/80 dark:text-slate-300">
            {{ $search === '' ? 'No public recipes yet.' : 'No recipes match your search.' }}
        </div>
    @else
        <p class="text-sm text-gray-600 dark:text-slate-400">
            Showing {{ $recipes->firstItem() }}–{{ $recipes->lastItem() }} of {{ $recipes->total() }} public recipes
        </p>

        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($recipes as $recipe)
                <article class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900/80">
                    <h2 class="text-lg font-semibold">
                        <a href="{{ route('recipes.show', $recipe->id) }}" class="text-blue-700 hover:underline dark:text-blue-300">
                            {{ $recipe->title }}
                        </a>
                    </h2>
                    @if ($recipe->tags !== [])
                        <p class="mt-2 text-sm text-gray-600 dark:text-slate-400">Tags: {{ implode(', ', $recipe->tags) }}</p>
                    @endif
                    @if ($recipe->classifications !== [])
                        <p class="mt-1 text-sm text-gray-600 dark:text-slate-400">Classifications: {{ collect($recipe->classifications)->pluck('name')->implode(', ') }}</p>
                    @endif
                    @if ($recipe->servings !== null)
                        <p class="mt-2 text-sm text-gray-600 dark:text-slate-400">Serves {{ rtrim(rtrim($recipe->servings, '0'), '.') }}</p>
                    @endif
                    <p class="mt-1 text-sm text-gray-600 dark:text-slate-400">Finalized {{ $recipe->finalizedAt->toFormattedDateString() }}</p>
                </article>
            @endforeach
        </div>

        <div>
            {{ $recipes->links() }}
        </div>
    @endif
</div>
