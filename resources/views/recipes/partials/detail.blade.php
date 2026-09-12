<article class="space-y-5 rounded-lg bg-white p-6 shadow dark:bg-slate-900 dark:text-slate-100">

    @include('recipes.partials.public-metadata')
    @if (session('status'))
        <x-auth-session-status :status="session('status')" />
    @endif

    @if ($publicRecipe?->attribution !== null)
        <p class="text-sm text-gray-600 dark:text-gray-400">
            By
            @if ($publicRecipe->attribution->profileId !== null)
                <a href="{{ route('public-profiles.show', $publicRecipe->attribution->profileId) }}" class="text-blue-700 underline dark:text-blue-300">{{ $publicRecipe->attribution->name }}</a>
            @else
                <span>{{ $publicRecipe->attribution->name }}</span>
            @endif
        </p>
    @endif

    @if ($remixAttribution !== null)
        <p class="rounded border border-gray-200 p-3 text-sm dark:border-slate-700">
            @if ($remixAttribution->sourceAvailable)
                Remixed from
                <a href="{{ route('recipes.show', $remixAttribution->sourceRecipeId) }}" class="text-blue-700 underline dark:text-blue-300">{{ $remixAttribution->sourceTitle }}</a>,
                version {{ $remixAttribution->versionNumber }}@if ($remixAttribution->sourceAttribution !== null) by
                    @if ($remixAttribution->sourceAttribution->profileId !== null)
                        <a href="{{ route('public-profiles.show', $remixAttribution->sourceAttribution->profileId) }}" class="text-blue-700 underline dark:text-blue-300">{{ $remixAttribution->sourceAttribution->name }}</a>
                    @else
                        {{ $remixAttribution->sourceAttribution->name }}
                    @endif
                @endif.
            @else
                Remixed from an unavailable recipe, version {{ $remixAttribution->versionNumber }}.
            @endif
        </p>
    @endif

    @if ($publicRecipe !== null)
        <p><strong>Suggested servings:</strong> {{ $publicRecipe->servings ?? 'Not supplied' }}</p>
        <p><strong>Visibility:</strong> {{ ucfirst($publicRecipe->visibility->value) }}</p>

        @auth
            @if ($bookmark !== null)
                <form method="POST" action="{{ route('bookmarks.destroy', $bookmark) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="rounded border px-4 py-2 text-sm font-semibold dark:border-slate-600">Remove bookmark</button>
                </form>
            @elseif ($recipe->isPubliclyViewable())
                <form method="POST" action="{{ route('bookmarks.store', $recipe) }}">
                    @csrf
                    <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white">Bookmark recipe</button>
                </form>
            @endif
        @else
            @if ($recipe->isPubliclyViewable())
                <p class="text-sm text-gray-600 dark:text-gray-400"><a href="{{ route('login') }}" class="text-blue-700 underline dark:text-blue-300">Sign in</a> to bookmark this recipe.</p>
            @endif
        @endauth

        @auth
            @can('remix', $recipe)
                <form method="POST" action="{{ route('recipes.remix.store', $recipe) }}" class="rounded border border-gray-200 p-4 dark:border-slate-700">
                    @csrf
                    <input type="hidden" name="source_version_id" value="{{ $publicRecipe->versionId }}">
                    <input type="hidden" name="operation_id" value="{{ $remixOperationId }}">
                    <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white">Create your own version</button>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">Creates an independent private draft that you own and can edit.</p>
                </form>
            @endcan
        @else
            @if ($recipe->isPubliclyViewable())
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    <a href="{{ route('login') }}" class="text-blue-700 underline dark:text-blue-300">Sign in</a>
                    to create an independent private remix.
                </p>
            @endif
        @endauth

        <p><strong>Stable version:</strong> {{ $publicRecipe->versionNumber }} ({{ $publicRecipe->versionId }})</p>
        <p class="text-sm text-gray-600 dark:text-gray-400">This page uses the current immutable finalized recipe version.</p>

        @can('startRevision', $recipe)
            @if ($recipe->activeRevision !== null)
                <div class="rounded border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                    <p>A private draft revision exists, based on finalized version {{ $recipe->activeRevision->baseVersion->version_number }}.</p>
                    <div class="mt-3 flex flex-wrap gap-3">
                        <a href="{{ route('recipes.edit', $recipe) }}" class="inline-flex rounded bg-blue-600 px-4 py-2 font-semibold text-white">Return to draft revision</a>
                        <a href="{{ route('recipes.show', [$recipe, 'preview' => 'draft']) }}" class="inline-flex rounded border border-amber-500 px-4 py-2 font-semibold">Preview saved draft revision</a>
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
    @else
        @if ($previewingRevision)
            <p><strong>Lifecycle:</strong> Private draft revision preview</p>
            <p class="text-sm text-gray-600 dark:text-gray-400">This preview uses the saved draft revision. The current finalized recipe remains unchanged.</p>
        @else
            <p><strong>Lifecycle:</strong> Draft</p>
            <p class="text-sm text-gray-600 dark:text-gray-400">This draft is private and unavailable to meal plans regardless of its intended visibility.</p>
        @endif
        <p><strong>Suggested servings:</strong> {{ $recipe->servings ?? 'Not supplied' }}</p>
        <p><strong>Visibility when finalized:</strong> {{ ucfirst($recipe->visibility->value) }}</p>
    @endif

    <section aria-labelledby="recipe-resize-heading" class="rounded border border-gray-200 p-4 dark:border-slate-700">
        <h2 id="recipe-resize-heading" class="font-semibold">Display quantities for</h2>
        <form method="GET" action="{{ route('recipes.show', $recipe) }}" class="mt-3 flex flex-wrap items-end gap-3">
            @if ($previewingRevision)
                <input type="hidden" name="preview" value="draft">
            @endif
            <div>
                <label for="display-servings" class="block text-sm font-medium">Servings</label>
                <input
                    id="display-servings"
                    name="servings"
                    type="number"
                    min="0.01"
                    max="99999999.99"
                    step="0.01"
                    value="{{ $quantityDisplay->requestedServings }}"
                    @disabled(! $quantityDisplay->canResize)
                    class="mt-1 w-36 rounded border-gray-300 dark:border-slate-600 dark:bg-slate-800"
                >
            </div>
            <button type="submit" @disabled(! $quantityDisplay->canResize) class="rounded bg-blue-600 px-4 py-2 font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">Apply</button>
            @if ($quantityDisplay->canResize)
                <a href="{{ route('recipes.show', $previewingRevision ? [$recipe, 'preview' => 'draft'] : $recipe) }}" class="rounded border px-4 py-2 font-semibold dark:border-slate-600">Reset to {{ $quantityDisplay->originalServings }}</a>
            @endif
        </form>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">Quantities are adjusted for display only. The saved recipe remains unchanged.</p>
        @if ($quantityDisplay->error !== null)
            <p role="alert" class="mt-2 text-sm font-medium text-red-700 dark:text-red-300">{{ $quantityDisplay->error }}</p>
        @endif
    </section>

    <section aria-labelledby="recipe-ingredients-heading">
        <h2 id="recipe-ingredients-heading" class="font-semibold">Ingredients</h2>
        @if ($quantityDisplay->ingredients === [])
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">No ingredient lines yet.</p>
        @else
            <ol class="mt-2 list-decimal space-y-2 pl-5">
                @foreach ($quantityDisplay->ingredients as $ingredient)
                    <li class="whitespace-pre-wrap">
                        @if ($ingredient['structured'])
                            <span>{{ $ingredient['quantity'] }} {{ $ingredient['unit'] }} {{ $ingredient['generic_wording'] }}</span>@if ($ingredient['notes'] !== null && trim($ingredient['notes']) !== '')<span>, {{ $ingredient['notes'] }}</span>@endif
                        @else
                            {{ $ingredient['original_text'] }}
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </section>

    @if ($nutritionEstimate !== null)
        <section aria-labelledby="recipe-nutrition-heading" class="space-y-4 rounded border border-gray-200 p-4 dark:border-slate-700">
            <div>
                <h2 id="recipe-nutrition-heading" class="font-semibold">{{ $nutritionEstimate['is_estimate'] ? 'Nutrition estimates' : 'Nutrition' }}</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400"><strong>Primary source:</strong> {{ $nutritionEstimate['source_label'] }}.</p>
                @if ($nutritionEstimate['is_estimate'])
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Calculated from the ingredient lines and catalogue data saved with this recipe version. Values are estimates, not verified nutrition facts.</p>
                @else
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Source-provided values are per serving; whole-recipe totals use this version's declared serving count.</p>
                    @if ($nutritionEstimate['source'] === 'imported_source' && is_array($nutritionEstimate['provenance']))
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Import {{ $nutritionEstimate['provenance']['recipe_import_id'] ?? 'unknown' }} · extractor {{ $nutritionEstimate['provenance']['extractor_version'] ?? 'unknown' }} · parser {{ $nutritionEstimate['provenance']['parser_version'] ?? 'unknown' }}</p>
                    @endif
                @endif
            </div>

            @if (! $nutritionEstimate['is_estimate'])
                <p class="rounded border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-100">These are source-provided nutrition values, not an ingredient calculation.</p>
            @elseif ($nutritionEstimate['status'] === 'complete')
                <p class="rounded border border-green-200 bg-green-50 p-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950/40 dark:text-green-100">
                    Complete estimate: every ingredient line contributed and none requires review.
                </p>
            @else
                <aside role="status" aria-labelledby="nutrition-limitations-heading" class="rounded border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                    <h3 id="nutrition-limitations-heading" class="font-semibold">Estimate limitations</h3>
                    <p class="mt-1">
                        @if ($nutritionEstimate['status'] === 'unavailable')
                            No nutrition values are currently available for this recipe estimate.
                        @else
                            This is a partial estimate. Available values remain useful, but the lines below are excluded from some or all calculations or require review.
                        @endif
                    </p>
                    @if ($nutritionEstimate['issues'] !== [])
                        <ol class="mt-3 list-decimal space-y-3 pl-5">
                            @foreach ($nutritionEstimate['issues'] as $issue)
                                <li>
                                    <p class="font-medium">{{ $issue['original_text'] !== '' ? $issue['original_text'] : 'Ingredient '.($issue['position'] + 1) }}</p>
                                    <ul class="mt-1 list-disc space-y-1 pl-5">
                                        @foreach ($issue['reasons'] as $reason)
                                            <li>{{ $reason }}</li>
                                        @endforeach
                                    </ul>
                                    @can('startRevision', $recipe)
                                        <a href="{{ route('recipes.edit', $recipe) }}#ingredient-line-{{ $issue['position'] + 1 }}" class="mt-2 inline-flex font-semibold text-blue-700 underline dark:text-blue-300">Review or correct ingredient {{ $issue['position'] + 1 }}</a>
                                    @endcan
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </aside>
            @endif

            <div class="grid gap-5 md:grid-cols-2">
                @foreach (['whole_recipe' => ($nutritionEstimate['is_estimate'] ? 'Estimated nutrition — whole recipe' : 'Nutrition — whole recipe'), 'per_serving' => ($nutritionEstimate['is_estimate'] ? 'Estimated nutrition — per serving' : 'Nutrition — per serving')] as $scope => $heading)
                    <section aria-labelledby="nutrition-{{ str_replace('_', '-', $scope) }}-heading">
                        <h3 id="nutrition-{{ str_replace('_', '-', $scope) }}-heading" class="font-semibold">{{ $heading }}</h3>
                        <dl class="mt-2 divide-y divide-gray-200 text-sm dark:divide-slate-700">
                            @foreach ($nutritionEstimate[$scope] as $nutrient)
                                <div class="flex items-center justify-between gap-4 py-2">
                                    <dt>{{ $nutrient['label'] }}</dt>
                                    <dd class="font-medium {{ $nutrient['available'] ? '' : 'text-gray-500 dark:text-gray-400' }}">{{ $nutrient['value'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>
                @endforeach
            </div>
            @foreach ($nutritionEstimate['comparisons'] as $comparison)
                <details class="rounded border border-gray-200 p-3 dark:border-slate-700">
                    <summary class="cursor-pointer font-semibold">Compare with {{ strtolower($comparison['source_label']) }}</summary>
                    @if ($comparison['source'] === 'ingredient_estimate')
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">This lower-precedence ingredient estimate is retained for comparison and remains an estimate.</p>
                    @endif
                    <dl class="mt-2 divide-y divide-gray-200 text-sm dark:divide-slate-700">
                        @foreach ($comparison['per_serving'] as $nutrient)
                            <div class="flex items-center justify-between gap-4 py-2">
                                <dt>{{ $nutrient['label'] }}</dt>
                                <dd class="font-medium {{ $nutrient['available'] ? '' : 'text-gray-500 dark:text-gray-400' }}">{{ $nutrient['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </details>
            @endforeach

            @can('overrideNutrition', $recipe)
                <details class="rounded border border-gray-200 p-3 dark:border-slate-700" @if ($errors->has('nutrients') || $errors->has('source_version_id')) open @endif>
                    <summary class="cursor-pointer font-semibold">{{ $nutritionEstimate['source'] === 'creator_override' ? 'Change creator override' : 'Add creator override' }}</summary>
                    <form method="POST" action="{{ route('recipes.nutrition-override.update', $recipe) }}" class="mt-3 space-y-3">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="source_version_id" value="{{ $publicRecipe->versionId }}">
                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach (['energy_kcal' => 'Energy (kcal)', 'energy_kj' => 'Energy (kJ)', 'fat' => 'Fat (g)', 'saturated_fat' => 'Saturated fat (g)', 'carbohydrates' => 'Carbohydrate (g)', 'sugars' => 'Sugars (g)', 'fibre' => 'Fibre (g)', 'protein' => 'Protein (g)', 'salt' => 'Salt (g)', 'sodium' => 'Sodium (mg)'] as $key => $label)
                                <label class="block text-sm"><span class="font-medium">{{ $label }}</span>
                                    <input name="nutrients[{{ $key }}]" inputmode="decimal" value="{{ old('nutrients.'.$key, $nutritionEstimate['override_values'][$key] ?? '') }}" class="mt-1 block w-full rounded border-gray-300 dark:border-slate-600 dark:bg-slate-800">
                                </label>
                            @endforeach
                        </div>
                        <label class="block text-sm"><span class="font-medium">Correction note (optional)</span>
                            <textarea name="note" maxlength="500" class="mt-1 block w-full rounded border-gray-300 dark:border-slate-600 dark:bg-slate-800">{{ old('note') }}</textarea>
                        </label>
                        @error('nutrients') <p role="alert" class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p> @enderror
                        @error('source_version_id') <p role="alert" class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p> @enderror
                        <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white">Save override</button>
                    </form>
                    @if ($nutritionEstimate['source'] === 'creator_override')
                        <form method="POST" action="{{ route('recipes.nutrition-override.destroy', $recipe) }}" class="mt-3">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="source_version_id" value="{{ $publicRecipe->versionId }}">
                            <button type="submit" class="rounded border border-red-300 px-4 py-2 text-sm font-semibold text-red-700 dark:border-red-800 dark:text-red-300">Remove override</button>
                        </form>
                    @endif
                </details>
                @if ($nutritionEstimate['history'] !== [])
                    <details class="rounded border border-gray-200 p-3 text-sm dark:border-slate-700">
                        <summary class="cursor-pointer font-semibold">Override history</summary>
                        <ol class="mt-2 space-y-2">
                            @foreach ($nutritionEstimate['history'] as $history)
                                <li>{{ ucfirst($history['event']) }} by {{ $history['actor'] }} at {{ $history['occurred_at']->toIso8601String() }}: {{ $history['prior_source'] }} → {{ $history['resulting_source'] }}@if ($history['note'] !== null). Note: {{ $history['note'] }}@endif</li>
                            @endforeach
                        </ol>
                    </details>
                @endif
            @endcan
        </section>
    @endif
    <section aria-labelledby="recipe-instructions-heading">
        <h2 id="recipe-instructions-heading" class="font-semibold">Instructions</h2>
        @if ($publicRecipe !== null)
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
        @elseif ($recipe->instructionSteps->isEmpty())
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

    @if ($publicRecipe !== null)
        @can('changeVisibility', $recipe)
            @php($nextVisibility = $publicRecipe->visibility === \App\Domain\Recipes\RecipeVisibility::Public ? \App\Domain\Recipes\RecipeVisibility::Private : \App\Domain\Recipes\RecipeVisibility::Public)
            <form method="POST" action="{{ route('recipes.visibility.update', $recipe) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="visibility" value="{{ $nextVisibility->value }}">
                <button type="submit" class="rounded border px-4 py-2 text-sm font-semibold dark:border-slate-600" @if ($nextVisibility === \App\Domain\Recipes\RecipeVisibility::Private) onclick="return confirm('Make this recipe private? Public access will stop immediately, but its finalized version will be preserved.')" @endif>
                    Make recipe {{ $nextVisibility->value }}
                </button>
            </form>
        @endcan
    @else
        @can('update', $recipe)
            <a href="{{ route('recipes.edit', $recipe) }}" class="inline-flex rounded bg-blue-600 px-4 py-2 text-white">{{ $previewingRevision ? 'Return to draft revision editor' : 'Edit draft' }}</a>
        @endcan
    @endif
</article>
