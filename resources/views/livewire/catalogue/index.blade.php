<div class="space-y-8">
    @if(session('status'))
        <x-auth-session-status :status="session('status')" />
    @endif

    <div class="flex flex-col gap-3 sm:flex-row">
        <input
            type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="Search by name or barcode…"
            class="min-w-0 flex-1 rounded border px-3 py-2"
        />
        @auth
            <a href="{{ route('ingredients.create') }}" class="inline-flex items-center justify-center rounded-md bg-sky-600 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-sky-500">
                Add ingredient
            </a>
        @endauth
    </div>

    <section aria-labelledby="shared-catalogue-heading" class="space-y-4">
        <h2 id="shared-catalogue-heading" class="text-lg font-semibold text-gray-900 dark:text-slate-100">Shared catalogue</h2>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @forelse($catalogueItems as $item)
                <a href="{{ route('catalogue.show', ['catalogueItem' => $item->id]) }}" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm hover:border-sky-400 dark:border-slate-800 dark:bg-slate-900/80">
                    <div class="flex gap-3">
                        @if($item->imageUrl)
                            <img src="{{ $item->imageUrl }}" alt="" class="h-16 w-16 rounded object-cover" />
                        @endif
                        <div class="min-w-0">
                            <h3 class="truncate font-medium text-gray-900 dark:text-slate-100">{{ $item->name }}</h3>
                            @if($item->barcode)
                                <p class="truncate text-sm text-gray-600 dark:text-gray-400">Barcode: {{ $item->barcode }}</p>
                            @endif
                            @if($item->pending)
                                <span class="mt-2 inline-flex rounded bg-amber-100 px-2 py-1 text-xs font-medium text-amber-800">Pending review</span>
                            @endif
                        </div>
                    </div>
                </a>
            @empty
                <p class="col-span-full rounded border border-dashed border-gray-300 p-4 text-sm text-gray-600 dark:border-slate-700 dark:text-gray-400">No visible catalogue records found.</p>
            @endforelse
        </div>

        {{ $catalogueItems->links() }}
    </section>

    @if($legacyIngredients !== null && $legacyIngredients->total() > 0)
        <section aria-labelledby="legacy-ingredients-heading" class="space-y-4">
            <div>
                <h2 id="legacy-ingredients-heading" class="text-lg font-semibold text-gray-900 dark:text-slate-100">Your legacy ingredients awaiting catalogue mapping</h2>
                <p class="text-sm text-gray-600 dark:text-gray-400">These private records remain available only to you during migration.</p>
            </div>

            <ul class="divide-y rounded border border-gray-200 dark:border-slate-700">
                @foreach($legacyIngredients as $ingredient)
                    <li class="flex items-center justify-between gap-3 p-4">
                        <a href="{{ route('ingredients.show', $ingredient) }}" class="font-medium text-sky-700 hover:underline dark:text-sky-300">{{ $ingredient->name }}</a>
                        @if($ingredient->barcode)
                            <span class="text-sm text-gray-600 dark:text-gray-400">{{ $ingredient->barcode }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>

            {{ $legacyIngredients->links() }}
        </section>
    @endif
</div>
