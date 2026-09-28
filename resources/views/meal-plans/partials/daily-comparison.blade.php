<section class="mt-4 rounded border border-gray-200 p-3 dark:border-slate-700" aria-label="Daily nutrition comparison">
    <h5 class="font-semibold text-gray-900 dark:text-slate-100">Daily nutrition comparison</h5>
    <p class="mt-1 text-sm text-gray-600 dark:text-slate-300">{{ $comparison['profile'] === null ? 'No target phase applies on this date.' : 'Target phase: '.$comparison['profile'] }}</p>
    <p class="mt-1 text-sm text-gray-600 dark:text-slate-300">Targets are personal planning guidance, not medical advice.</p>
    <div class="mt-3 overflow-x-auto">
        <table class="min-w-full text-left text-sm text-gray-800 dark:text-slate-200">
            <thead><tr class="border-b border-gray-200 dark:border-slate-700">
                <th scope="col" class="p-2">Nutrient</th>
                <th scope="col" class="p-2">Daily target</th>
                <th scope="col" class="p-2">Planned</th>
                <th scope="col" class="p-2">Consumed</th>
            </tr></thead>
            <tbody>
                @foreach ($comparison['rows'] as $row)
                    <tr class="border-b border-gray-100 align-top dark:border-slate-800">
                        <th scope="row" class="p-2 font-medium">{{ $row['label'] }}</th>
                        <td class="p-2">{{ $row['target'] }}</td>
                        @foreach (['planned', 'consumed'] as $kind)
                            <td class="p-2">
                                <span class="block whitespace-nowrap">{{ $row[$kind]['value'] }}</span>
                                <span class="block">{{ $row[$kind]['status'] }}</span>
                                @if ($row[$kind]['note'] !== '')<span class="block text-xs text-gray-600 dark:text-slate-300">{{ $row[$kind]['note'] }}</span>@endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
