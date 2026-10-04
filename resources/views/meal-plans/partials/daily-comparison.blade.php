<section class="mt-4 rounded border border-gray-200 p-3 dark:border-slate-700" aria-label="Daily nutrition comparison">
    <h5 class="font-semibold text-gray-900 dark:text-slate-100">Daily nutrition comparison</h5>
    <p class="mt-1 text-sm text-gray-600 dark:text-slate-300">{{ $comparison['profile'] === null ? 'No target phase applies on this date.' : 'Target phase: '.$comparison['profile'] }}</p>
    <p class="mt-1 text-sm text-gray-600 dark:text-slate-300">Targets are personal planning guidance, not medical advice.</p>
    <div class="mt-3 grid gap-3 text-sm text-gray-800 dark:text-slate-200">
        @foreach ($comparison['rows'] as $row)
            <section aria-label="{{ $row['label'] }} daily comparison" class="min-w-0 rounded border border-gray-200 p-3 dark:border-slate-700" data-nutrient-comparison>
                <h6 class="font-semibold">{{ $row['label'] }}</h6>
                <dl class="mt-2 grid gap-3 sm:grid-cols-3">
                    <div class="min-w-0"><dt class="font-medium">Daily target</dt><dd>{{ $row['target'] }}</dd></div>
                    @foreach (['planned', 'consumed'] as $kind)
                        <div class="min-w-0">
                            <dt class="font-medium">{{ ucfirst($kind) }}</dt>
                            <dd>
                                <span class="block">{{ $row[$kind]['value'] }}</span>
                                <span class="block">{{ $row[$kind]['status'] }}</span>
                                @if ($row[$kind]['note'] !== '')<span class="block text-xs text-gray-600 dark:text-slate-300">{{ $row[$kind]['note'] }}</span>@endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endforeach
    </div>
</section>
