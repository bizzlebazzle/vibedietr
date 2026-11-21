<nav aria-label="Recipe views" class="mb-6 flex flex-wrap gap-4">
    @foreach (['discover' => 'Discover recipes', 'bookmarks' => 'My bookmarks', 'mine' => 'My recipes', 'drafts' => 'My draft recipes'] as $key => $label)
        <a href="{{ route('recipes.index', $key === 'discover' ? [] : ['view' => $key]) }}" @if ($view === $key) aria-current="page" @endif class="inline-flex min-h-11 items-center font-semibold text-blue-700 underline dark:text-blue-300">{{ $label }}</a>
    @endforeach
</nav>
