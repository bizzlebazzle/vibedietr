<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">{{ $heading }}: {{ $organization->name }}</h2></x-slot>
    <div class="py-12">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('status')) <x-auth-session-status :status="session('status')" /> @endif
            <a href="{{ route($routePrefix.'.index') }}" class="text-blue-700 hover:underline dark:text-blue-300">Back to {{ $noun }}s</a>
            <section class="rounded border bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <form method="POST" action="{{ route($routePrefix.'.update', $organization) }}" class="flex flex-wrap items-end gap-3">
                    @csrf @method('PATCH')
                    <div class="min-w-0 flex-1">
                        <label for="organization-name" class="block text-sm font-medium">Name</label>
                        <input id="organization-name" name="name" value="{{ old('name', $organization->name) }}" maxlength="100" required class="mt-1 w-full rounded border-gray-300 dark:border-slate-600 dark:bg-slate-800">
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>
                    <button class="rounded bg-blue-600 px-4 py-2 font-semibold text-white">Rename</button>
                </form>
                <form method="POST" action="{{ route($routePrefix.'.destroy', $organization) }}" class="mt-4" onsubmit="return confirm('Delete this {{ $noun }}? Recipes and bookmarks will not be deleted.')">
                    @csrf @method('DELETE')
                    <button class="rounded border border-red-600 px-4 py-2 text-sm font-semibold text-red-700 dark:text-red-300">Delete {{ $noun }}</button>
                </form>
            </section>
            <div class="grid gap-6 lg:grid-cols-2">
                <section class="space-y-4 rounded border bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-lg font-semibold">Owned recipes</h2>
                    <form method="POST" action="{{ route($routePrefix.'.recipes.store', $organization) }}" class="space-y-2">
                        @csrf
                        <label for="recipe-id" class="block text-sm font-medium">{{ $applyVerb }} owned recipe</label>
                        <div class="flex gap-2">
                            <select id="recipe-id" name="recipe_id" required class="min-w-0 flex-1 rounded border-gray-300 dark:border-slate-600 dark:bg-slate-800">
                                @foreach ($ownedRecipes as $ownedRecipe)<option value="{{ $ownedRecipe->id }}">{{ $ownedRecipe->title }}</option>@endforeach
                            </select>
                            <button @disabled($ownedRecipes->isEmpty()) class="rounded bg-blue-600 px-3 py-2 text-white disabled:opacity-50">{{ $applyVerb }}</button>
                        </div>
                    </form>
                    @forelse ($recipes as $recipe)
                        <article class="rounded border p-3 dark:border-slate-700">
                            <a href="{{ route('recipes.show', $recipe) }}" class="font-semibold text-blue-700 hover:underline dark:text-blue-300">{{ $recipe->title }}</a>
                            <form method="POST" action="{{ route($routePrefix.'.recipes.destroy', [$organization, $recipe]) }}" class="mt-2">
                                @csrf @method('DELETE')<button class="text-sm font-semibold text-red-700 dark:text-red-300">Remove</button>
                            </form>
                        </article>
                    @empty <p class="text-sm text-gray-600 dark:text-slate-400">No owned recipes organized here.</p> @endforelse
                </section>
                <section class="space-y-4 rounded border bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-lg font-semibold">Bookmarks</h2>
                    <form method="POST" action="{{ route($routePrefix.'.bookmarks.store', $organization) }}" class="space-y-2">
                        @csrf
                        <label for="bookmark-id" class="block text-sm font-medium">{{ $applyVerb }} bookmark</label>
                        <div class="flex gap-2">
                            <select id="bookmark-id" name="bookmark_id" required class="min-w-0 flex-1 rounded border-gray-300 dark:border-slate-600 dark:bg-slate-800">
                                @foreach ($ownedBookmarks as $ownedBookmark)<option value="{{ $ownedBookmark->id }}">{{ $ownedBookmark->isAvailable() ? $ownedBookmark->publicRecipe->title : 'Recipe unavailable' }}</option>@endforeach
                            </select>
                            <button @disabled($ownedBookmarks->isEmpty()) class="rounded bg-blue-600 px-3 py-2 text-white disabled:opacity-50">{{ $applyVerb }}</button>
                        </div>
                    </form>
                    @forelse ($bookmarkItems as $bookmark)
                        <article class="rounded border p-3 dark:border-slate-700">
                            @if ($bookmark->isAvailable())
                                <a href="{{ route('recipes.show', $bookmark->publicRecipe->id) }}" class="font-semibold text-blue-700 hover:underline dark:text-blue-300">{{ $bookmark->publicRecipe->title }}</a>
                            @else
                                <h3 class="font-semibold">Recipe unavailable</h3>
                                <p class="text-sm text-gray-600 dark:text-slate-400">This recipe is no longer publicly available.</p>
                            @endif
                            <form method="POST" action="{{ route($routePrefix.'.bookmarks.destroy', [$organization, $bookmark->id]) }}" class="mt-2">
                                @csrf @method('DELETE')<button class="text-sm font-semibold text-red-700 dark:text-red-300">Remove</button>
                            </form>
                        </article>
                    @empty <p class="text-sm text-gray-600 dark:text-slate-400">No bookmarks organized here.</p> @endforelse
                </section>
            </div>
        </div>
    </div>
</x-app-layout>
