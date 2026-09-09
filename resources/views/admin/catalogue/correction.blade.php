<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-gray-800 dark:text-gray-100">Catalogue correction proposal</h1></x-slot>
    <div class="mx-auto max-w-5xl p-6 space-y-6 text-gray-900 dark:text-gray-100">
        <a class="underline" href="{{ route('admin.catalogue.index', ['type' => 'correction_proposal']) }}">Back to queue</a>
        <p><strong>State:</strong> {{ $proposal->state->value }}</p>
        <p><strong>Private proposer reason:</strong> {{ $proposal->reason }}</p>
        <p><strong>Base version:</strong> {{ $proposal->base_catalogue_item_version_id }} · <strong>Current version:</strong> {{ $review->currentVersionId }}</p>
        @if($review->stale)
            <div class="rounded border border-amber-400 p-4" role="status"><strong>Stale proposal.</strong> Current catalogue state has advanced since the proposer viewed it. Review every base/current/proposed value. Conflicts: {{ implode(', ', $review->conflicts()) ?: 'none; changes are on unrelated fields' }}.</div>
        @endif
        <div class="overflow-x-auto"><table class="w-full text-left">
            <thead><tr><th class="p-2">Field</th><th class="p-2">Base</th><th class="p-2">Current</th><th class="p-2">Proposed</th><th class="p-2">Conflict</th></tr></thead>
            <tbody>@foreach($review->changes as $change)<tr class="border-t">
                <th class="p-2">{{ $change['field'] }}</th>
                <td class="p-2"><code>{{ json_encode($change['before']) }}</code></td>
                <td class="p-2"><code>{{ json_encode($change['current']) }}</code></td>
                <td class="p-2"><code>{{ json_encode($change['proposed']) }}</code></td>
                <td class="p-2">{{ $change['conflict'] ? 'Changed since base' : 'Unchanged since base' }}</td>
            </tr>@endforeach</tbody>
        </table></div>
        @if($proposal->state->value === 'pending')
            <div class="flex flex-wrap gap-6">
                <form method="POST" action="{{ route('admin.catalogue.corrections.accept', $proposal) }}" class="space-y-3">
                    @csrf
                    @if($review->stale)<label class="flex gap-2"><input type="checkbox" name="stale_reviewed" value="1" required> I reviewed base, current, proposed values and conflicts</label>@endif
                    <x-primary-button>Accept whole proposal</x-primary-button>
                </form>
                <form method="POST" action="{{ route('admin.catalogue.corrections.reject', $proposal) }}" class="space-y-3">
                    @csrf
                    <select name="reason_code" class="rounded dark:bg-gray-800"><option value="insufficient_evidence">Insufficient evidence</option><option value="reviewed">Reviewed  retain current data</option></select>
                    <textarea name="note" maxlength="500" placeholder="Optional private moderator note" class="block rounded dark:bg-gray-800"></textarea>
                    <x-danger-button>Reject proposal</x-danger-button>
                </form>
            </div>
        @endif
    </div>
</x-app-layout>
