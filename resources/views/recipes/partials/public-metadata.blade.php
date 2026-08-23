@php
    $shownTags = $publicRecipe?->freeFormTags ?? $recipe->publicTags()->pluck('name')->all();
    $shownClassifications = $publicRecipe?->managedClassifications ?? $recipe->managedTerms()->get()->map(fn ($term) => ['id' => $term->id, 'category' => $term->category->value, 'name' => $term->name])->all();
@endphp

@if ($shownTags !== [] || $shownClassifications !== [])
    <section aria-labelledby="recipe-public-metadata-heading" class="rounded border border-gray-200 p-4 dark:border-slate-700">
        <h2 id="recipe-public-metadata-heading" class="font-semibold">Tags and classifications</h2>
        @if ($shownTags !== [])
            <div class="mt-3">
                <h3 class="text-sm font-semibold">Tags</h3>
                <p class="mt-1">{{ implode(', ', $shownTags) }}</p>
            </div>
        @endif
        @foreach (\App\Domain\Recipes\ManagedRecipeTermCategory::cases() as $category)
            @php($categoryTerms = collect($shownClassifications)->where('category', $category->value)->pluck('name')->all())
            @if ($categoryTerms !== [])
                <div class="mt-3">
                    <h3 class="text-sm font-semibold">{{ $category->label() }}</h3>
                    <p class="mt-1">{{ implode(', ', $categoryTerms) }}</p>
                </div>
            @endif
        @endforeach
    </section>
@endif

@auth
    @if (auth()->id() === $recipe->user_id)
        <section aria-labelledby="recipe-tag-management-heading" class="rounded border border-gray-200 p-4 dark:border-slate-700">
            <h2 id="recipe-tag-management-heading" class="font-semibold">Manage public tags</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">These are public metadata whenever this recipe is public. Private organisational tags remain separate.</p>
            <form method="POST" action="{{ route('recipes.public-tags.store', $recipe) }}" class="mt-3 flex flex-wrap items-end gap-3">
                @csrf
                <div class="min-w-64 flex-1">
                    <label for="public-tag-name" class="block text-sm font-medium">Free-form tag</label>
                    <input id="public-tag-name" name="name" maxlength="100" required class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 dark:bg-slate-800">
                </div>
                <button class="rounded bg-blue-600 px-4 py-2 font-semibold text-white">Add tag</button>
            </form>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($recipe->publicTags()->get() as $tag)
                    <form method="POST" action="{{ route('recipes.public-tags.destroy', [$recipe, $tag]) }}">
                        @csrf
                        @method('DELETE')
                        <button class="rounded border px-3 py-1 text-sm dark:border-slate-600">Remove {{ $tag->name }}</button>
                    </form>
                @endforeach
            </div>

            <form method="POST" action="{{ route('recipes.managed-classifications.store', $recipe) }}" class="mt-5 flex flex-wrap items-end gap-3">
                @csrf
                <div class="min-w-64 flex-1">
                    <label for="managed-term" class="block text-sm font-medium">Controlled classification</label>
                    <select id="managed-term" name="managed_term_id" class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 dark:bg-slate-800">
                        @foreach ($activeManagedTerms as $term)
                            <option value="{{ $term->id }}">{{ $term->category->label() }} — {{ $term->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="rounded bg-blue-600 px-4 py-2 font-semibold text-white">Add classification</button>
            </form>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($recipe->managedTerms()->get() as $term)
                    <form method="POST" action="{{ route('recipes.managed-classifications.destroy', [$recipe, $term]) }}">
                        @csrf
                        @method('DELETE')
                        <button class="rounded border px-3 py-1 text-sm dark:border-slate-600">Remove {{ $term->name }}</button>
                    </form>
                @endforeach
            </div>
        </section>

        @if ($pendingTagSuggestions->isNotEmpty())
            <section aria-labelledby="recipe-tag-suggestions-heading" class="rounded border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/40">
                <h2 id="recipe-tag-suggestions-heading" class="font-semibold">Classification suggestions</h2>
                <p class="mt-1 text-sm">Suggestions do not become public metadata until you accept them.</p>
                <div class="mt-3 space-y-3">
                    @foreach ($pendingTagSuggestions as $suggestion)
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <span>{{ $suggestion->term->category->label() }} — {{ $suggestion->term->name }}</span>
                            <div class="flex gap-2">
                                <form method="POST" action="{{ route('managed-recipe-term-suggestions.accept', $suggestion) }}">@csrf<button class="rounded bg-blue-600 px-3 py-1 text-sm font-semibold text-white">Accept</button></form>
                                <form method="POST" action="{{ route('managed-recipe-term-suggestions.reject', $suggestion) }}">@csrf<button class="rounded border px-3 py-1 text-sm font-semibold dark:border-slate-600">Reject</button></form>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    @endif
@endauth
