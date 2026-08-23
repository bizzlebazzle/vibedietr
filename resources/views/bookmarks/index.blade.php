<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-slate-100">Bookmarks</h2>
    </x-slot>

    <div class="py-12">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('status'))
                <x-auth-session-status :status="session('status')" />
            @endif

            <div>
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-slate-100">Your recipe bookmarks</h1>
                <p class="mt-1 text-sm text-gray-600 dark:text-slate-400">Private pointers to the latest public versions of saved recipes.</p>
            </div>

            @if ($bookmarks->isEmpty())
                <div class="rounded border border-gray-200 bg-white p-6 text-gray-700 shadow-sm dark:border-slate-800 dark:bg-slate-900/80 dark:text-slate-300">
                    You have not bookmarked any recipes yet.
                </div>
            @else
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($bookmarks as $bookmark)
                        <article class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900/80">
                            @if ($bookmark->isAvailable())
                                <h2 class="text-lg font-semibold">
                                    <a href="{{ route('recipes.show', $bookmark->publicRecipe->id) }}" class="text-blue-700 hover:underline dark:text-blue-300">
                                        {{ $bookmark->publicRecipe->title }}
                                    </a>
                                </h2>
                                @if ($bookmark->publicRecipe->servings !== null)
                                    <p class="mt-2 text-sm text-gray-600 dark:text-slate-400">Serves {{ rtrim(rtrim($bookmark->publicRecipe->servings, '0'), '.') }}</p>
                                @endif
                                <p class="mt-1 text-sm text-gray-600 dark:text-slate-400">Current version finalized {{ $bookmark->publicRecipe->finalizedAt->toFormattedDateString() }}</p>
                            @else
                                <h2 class="text-lg font-semibold">Recipe unavailable</h2>
                                <p class="mt-2 text-sm text-gray-600 dark:text-slate-400">This recipe is no longer publicly available.</p>
                            @endif

                            <p class="mt-3 text-sm text-gray-600 dark:text-slate-400">Bookmarked {{ $bookmark->bookmarkedAt->toFormattedDateString() }}</p>
                            <form method="POST" action="{{ route('bookmarks.destroy', $bookmark->id) }}" class="mt-3">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="rounded border px-4 py-2 text-sm font-semibold dark:border-slate-600">Remove bookmark</button>
                            </form>
                        </article>
                    @endforeach
                </div>

                <div>
                    {{ $bookmarks->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
