<article class="space-y-6 text-gray-900 dark:text-slate-100">
    <a href="{{ route('catalogue.index') }}" class="text-sm font-medium text-sky-700 hover:underline dark:text-sky-300">Back to catalogue</a>

    <div class="flex flex-col gap-5 sm:flex-row">
        @if($item->imageUrl)
            <img src="{{ $item->imageUrl }}" alt="" class="h-48 w-48 rounded-lg border border-gray-200 object-cover dark:border-slate-700" />
        @endif

        <div class="min-w-0 flex-1 space-y-4">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-semibold">{{ $item->name }}</h1>
                    @if($item->pending)
                        <span class="rounded bg-amber-100 px-2 py-1 text-xs font-medium text-amber-800">Pending review</span>
                    @endif
                </div>
                @if($item->barcode)
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Barcode: {{ $item->barcode }}</p>
                @endif
            </div>

            <dl class="grid gap-3 sm:grid-cols-3">
                <div class="rounded border border-gray-200 p-3 dark:border-slate-700">
                    <dt class="text-xs font-semibold uppercase text-gray-500">Quantity</dt>
                    <dd class="mt-1">{{ trim(($item->quantity ?? '').' '.($item->quantityUnit ?? '')) ?: 'Not set' }}</dd>
                </div>
                <div class="rounded border border-gray-200 p-3 dark:border-slate-700">
                    <dt class="text-xs font-semibold uppercase text-gray-500">Serving</dt>
                    <dd class="mt-1">{{ trim(($item->servingQuantity ?? '').' '.($item->servingQuantityUnit ?? '')) ?: 'Not set' }}</dd>
                </div>
                <div class="rounded border border-gray-200 p-3 dark:border-slate-700">
                    <dt class="text-xs font-semibold uppercase text-gray-500">Recommended servings</dt>
                    <dd class="mt-1">{{ $item->recommendedServings ?? 'Not set' }}</dd>
                </div>
            </dl>
        </div>
    </div>

    @foreach(['per_100g' => 'Nutrition per 100g', 'per_serving' => 'Nutrition per serving'] as $bucket => $heading)
        @php($values = $item->nutriments[$bucket] ?? [])
        @if(is_array($values) && $values !== [])
            <section class="rounded border border-gray-200 p-4 dark:border-slate-700">
                <h2 class="font-semibold">{{ $heading }}</h2>
                <dl class="mt-3 divide-y divide-gray-200 dark:divide-slate-700">
                    @foreach($values as $nutrient => $value)
                        @if(is_scalar($value))
                            <div class="flex justify-between gap-4 py-2 text-sm">
                                <dt>{{ str_replace('_', ' ', ucfirst($nutrient)) }}</dt>
                                <dd>{{ $value }}</dd>
                            </div>
                        @endif
                    @endforeach
                </dl>
            </section>
        @endif
    @endforeach

    <div class="grid gap-4 sm:grid-cols-2">
        <section class="rounded border border-gray-200 p-4 dark:border-slate-700">
            <h2 class="font-semibold">Keywords</h2>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $item->keywords === [] ? 'None' : implode(', ', $item->keywords) }}</p>
        </section>
        <section class="rounded border border-gray-200 p-4 dark:border-slate-700">
            <h2 class="font-semibold">Categories</h2>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $item->categories === [] ? 'None' : implode(', ', $item->categories) }}</p>
        </section>
    </div>
</article>
