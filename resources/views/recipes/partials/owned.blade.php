<div class="space-y-6">
    <h1 class="text-2xl font-semibold">{{ $view === 'mine' ? 'My recipes' : 'My draft recipes' }}</h1>
    @if ($recipes->isEmpty())
        <p>{{ $view === 'mine' ? 'You have no finalized recipes yet.' : 'You have no draft recipes or unfinished revisions.' }}</p>
        <x-recipe-start-actions />
    @else
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($recipes as $recipe)
                <article class="min-w-0 break-words rounded-lg border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-lg font-semibold">
                        @if ($view === 'mine')
                            <a class="text-blue-700 underline dark:text-blue-300" href="{{ route('recipes.show', $recipe) }}">{{ $recipe->currentVersion->snapshot['title'] }}</a>
                        @else
                            {{ $recipe->title }}
                        @endif
                    </h2>
                    <p class="mt-2 text-sm">{{ $view === 'drafts' ? ($recipe->isFinalized() ? 'Draft revision' : 'Draft') : ucfirst($recipe->visibility->value) }}</p>
                    @if ($view === 'mine' && $recipe->activeRevision !== null)
                        <p class="mt-2 text-sm">Unfinished draft revision</p>
                    @endif
                    <a class="mt-3 inline-flex min-h-11 items-center text-blue-700 underline dark:text-blue-300" href="{{ route('recipes.edit', $recipe) }}">{{ $view === 'drafts' || $recipe->activeRevision !== null ? 'Continue editing' : 'Edit recipe' }}</a>
                </article>
            @endforeach
        </div>
        {{ $recipes->links() }}
    @endif
</div>
