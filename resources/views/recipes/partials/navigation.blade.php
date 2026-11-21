<nav aria-label="Recipe views" class="border-b border-gray-200 bg-white text-gray-900 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-100">
    <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-2 gap-x-4 sm:flex sm:flex-wrap sm:gap-x-8">
            @foreach (['discover' => 'Discover recipes', 'bookmarks' => 'My bookmarks', 'mine' => 'My recipes', 'drafts' => 'My draft recipes'] as $key => $label)
                <x-nav-link :href="route('recipes.index', $key === 'discover' ? [] : ['view' => $key])" :active="$view === $key" class="min-h-12 py-3">{{ $label }}</x-nav-link>
            @endforeach
        </div>
    </div>
</nav>
