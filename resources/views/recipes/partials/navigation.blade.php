<nav aria-label="Recipe views">
    <x-secondary-bar>
        <div class="grid w-full grid-cols-2 gap-x-4 sm:flex sm:flex-wrap sm:gap-x-8">
            @foreach (['discover' => 'Discover recipes', 'bookmarks' => 'My bookmarks', 'mine' => 'My recipes', 'drafts' => 'My draft recipes'] as $key => $label)
                <x-nav-link :href="route('recipes.index', $key === 'discover' ? [] : ['view' => $key])" :active="$view === $key" class="min-h-12 py-3">{{ $label }}</x-nav-link>
            @endforeach
        </div>
    </x-secondary-bar>
</nav>
