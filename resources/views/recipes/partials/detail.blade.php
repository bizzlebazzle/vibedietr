<article class="space-y-5 rounded-lg bg-white p-6 shadow dark:bg-slate-900 dark:text-slate-100">
    @if (session('status'))
        <x-auth-session-status :status="session('status')" />
    @endif

    @if ($publicRecipe !== null)
        <p><strong>Suggested servings:</strong> {{ $publicRecipe->servings ?? 'Not supplied' }}</p>
        <p><strong>Visibility:</strong> {{ ucfirst($publicRecipe->visibility->value) }}</p>
        <p><strong>Stable version:</strong> {{ $publicRecipe->versionNumber }} ({{ $publicRecipe->versionId }})</p>
        <p class="text-sm text-gray-600 dark:text-gray-400">This page uses the current immutable finalized recipe version.</p>

        @can('startRevision', $recipe)
            @if ($recipe->activeRevision !== null)
                <div class="rounded border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                    <p>A private draft revision exists, based on finalized version {{ $recipe->activeRevision->baseVersion->version_number }}.</p>
                    <div class="mt-3 flex flex-wrap gap-3">
                        <a href="{{ route('recipes.edit', $recipe) }}" class="inline-flex rounded bg-blue-600 px-4 py-2 font-semibold text-white">Return to draft revision</a>
                        <form method="POST" action="{{ route('recipes.revision.destroy', $recipe) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" onclick="return confirm('Abandon this private draft revision? The current finalized version will remain unchanged.')" class="rounded border border-red-300 px-4 py-2 font-semibold text-red-700 dark:border-red-800 dark:text-red-300">Abandon draft revision</button>
                        </form>
                    </div>
                </div>
            @else
                <a href="{{ route('recipes.edit', $recipe) }}" class="inline-flex rounded bg-blue-600 px-4 py-2 text-white">Edit recipe</a>
            @endif
        @endcan

        <section aria-labelledby="recipe-ingredients-heading">
            <h2 id="recipe-ingredients-heading" class="font-semibold">Ingredients</h2>
            <ol class="mt-2 list-decimal space-y-2 pl-5">
                @foreach ($publicRecipe->ingredients as $ingredient)
                    <li class="whitespace-pre-wrap">{{ $ingredient['text'] }}</li>
                @endforeach
            </ol>
        </section>

        <section aria-labelledby="recipe-instructions-heading">
            <h2 id="recipe-instructions-heading" class="font-semibold">Instructions</h2>
            <ol class="mt-2 space-y-3">
                @foreach ($publicRecipe->instructions as $instruction)
                    <li class="flex gap-3">
                        <span class="font-medium text-gray-500 dark:text-gray-400">{{ $loop->iteration }}.</span>
                        <div>
                            @if ($instruction['section'] !== null)
                                <p class="text-sm font-semibold text-blue-700 dark:text-blue-300">{{ $instruction['section'] }}</p>
                            @endif
                            <p class="whitespace-pre-wrap">{{ $instruction['text'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>

        @can('changeVisibility', $recipe)
            @php($nextVisibility = $publicRecipe->visibility === \App\Domain\Recipes\RecipeVisibility::Public ? \App\Domain\Recipes\RecipeVisibility::Private : \App\Domain\Recipes\RecipeVisibility::Public)
            <form method="POST" action="{{ route('recipes.visibility.update', $recipe) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="visibility" value="{{ $nextVisibility->value }}">
                <button
                    type="submit"
                    class="rounded border px-4 py-2 text-sm font-semibold dark:border-slate-600"
                    @if ($nextVisibility === \App\Domain\Recipes\RecipeVisibility::Private)
                        onclick="return confirm('Make this recipe private? Public access will stop immediately, but its finalized version will be preserved.')"
                    @endif
                >
                    Make recipe {{ $nextVisibility->value }}
                </button>
            </form>
        @endcan
    @else
        <p><strong>Lifecycle:</strong> Draft</p>
        <p><strong>Suggested servings:</strong> {{ $recipe->servings ?? 'Not supplied' }}</p>
        <p><strong>Visibility when finalized:</strong> {{ ucfirst($recipe->visibility->value) }}</p>
        <p class="text-sm text-gray-600 dark:text-gray-400">This draft is private and unavailable to meal plans regardless of its intended visibility.</p>

        <section aria-labelledby="recipe-ingredients-heading">
            <h2 id="recipe-ingredients-heading" class="font-semibold">Ingredients</h2>
            @if ($recipe->ingredientLines->isEmpty())
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">No ingredient lines yet.</p>
            @else
                <ol class="mt-2 list-decimal space-y-2 pl-5">
                    @foreach ($recipe->ingredientLines as $line)
                        <li class="whitespace-pre-wrap">{{ $line->original_text }}</li>
                    @endforeach
                </ol>
            @endif
        </section>

        <section aria-labelledby="recipe-instructions-heading">
            <h2 id="recipe-instructions-heading" class="font-semibold">Instructions</h2>
            @if ($recipe->instructionSteps->isEmpty())
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">No instruction steps yet.</p>
            @else
                <ol class="mt-2 space-y-3">
                    @foreach ($recipe->instructionSteps as $step)
                        <li class="flex gap-3">
                            <span class="font-medium text-gray-500 dark:text-gray-400">{{ $loop->iteration }}.</span>
                            <div>
                                @if ($step->section !== null)
                                    <p class="text-sm font-semibold text-blue-700 dark:text-blue-300">{{ $step->section->name }}</p>
                                @endif
                                <p class="whitespace-pre-wrap">{{ $step->text }}</p>
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>

        @can('update', $recipe)
            <a href="{{ route('recipes.edit', $recipe) }}" class="inline-flex rounded bg-blue-600 px-4 py-2 text-white">Edit draft</a>
        @endcan
    @endif
</article>
