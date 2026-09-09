<section class="p-4 rounded border border-gray-300 dark:border-gray-600 break-words space-y-2">
    <h2 class="text-lg font-semibold">#{{ $item->id }} · {{ $item->currentVersion?->name ?? 'Unnamed catalogue identity' }}</h2>
    <p>State: {{ $item->status->value }} · Source: {{ $item->source->value }}</p>
    @if ($item->currentVersion)
        <p>Version: {{ $item->currentVersion->id }} · {{ $item->currentVersion->version_number }}</p>
        <dl class="grid sm:grid-cols-2 gap-2">
            @foreach (['manual_food_classification', 'brand', 'manufacturer', 'food_form', 'preparation', 'treatment', 'composition', 'package_count', 'item_type', 'amount_per_item', 'amount_per_item_unit', 'serving_amount', 'serving_amount_unit'] as $field)
                <div><dt class="font-medium">{{ ucfirst(str_replace('_', ' ', $field)) }}</dt><dd>{{ $item->currentVersion->getRawOriginal($field) ?? 'Not available' }}</dd></div>
            @endforeach
        </dl>
        <h3 class="font-semibold">Nutrition facts</h3>
        @forelse ($item->currentVersion->nutrientValues as $fact)
            <p>{{ $fact->getRawOriginal('nutrient') }} · {{ $fact->getRawOriginal('basis') }} · {{ $fact->getRawOriginal('value') ?? 'Not available' }} {{ $fact->getRawOriginal('unit') }} · {{ $fact->getRawOriginal('provenance') }}</p>
        @empty <p>No nutrition facts supplied.</p>@endforelse
    @else <p>No current version exists. This identity requires separate data review.</p>@endif
</section>
