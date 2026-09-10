<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-gray-800 dark:text-gray-100">OpenFoodFacts refresh</h1></x-slot>
    <div class="mx-auto max-w-5xl p-6 space-y-6 text-gray-900 dark:text-gray-100">
        <a class="underline" href="{{ route('admin.catalogue.index', ['type' => 'provider_refresh']) }}">Back to queue</a>
        @if(session('status'))<x-auth-session-status :status="session('status')" />@endif
        <p><strong>Item:</strong> {{ $refresh->catalogueItem->currentVersion?->name }}</p>
        <p><strong>State:</strong> {{ str_replace('_', ' ', $refresh->state->value) }}</p>
        <p><strong>Provider:</strong> OpenFoodFacts · <strong>Source identity:</strong> {{ $refresh->source_identifier }}</p>
        <p><strong>Pinned base version:</strong> {{ $refresh->base_catalogue_item_version_id }}</p>
        @if($refresh->failure_code)<p><strong>Failure category:</strong> {{ str_replace('_', ' ', $refresh->failure_code) }}</p>@endif
        @if($review !== null)
            <p>Review provider-supplied fields individually, then accept or reject the whole proposal.</p>
            @if($review->stale)
                <div class="rounded border border-amber-400 p-4" role="status"><strong>Stale proposal.</strong> Current state advanced after this request. Conflicts: {{ implode(', ', $review->conflicts()) ?: 'none; later changes are unrelated' }}.</div>
            @endif
            <div class="overflow-x-auto"><table class="w-full text-left">
                <thead><tr><th class="p-2">Field</th><th class="p-2">Base</th><th class="p-2">Current</th><th class="p-2">Proposed</th><th class="p-2">Provider evidence</th><th class="p-2">Conflict</th></tr></thead>
                <tbody>@foreach($review->changes as $change)<tr class="border-t">
                    <th class="p-2">{{ $change['field'] }}</th>
                    <td class="p-2"><code>{{ json_encode($change['before']) }}</code></td>
                    <td class="p-2"><code>{{ json_encode($change['current']) }}</code></td>
                    <td class="p-2"><code>{{ json_encode($change['proposed']) }}</code></td>
                    <td class="p-2"><code>{{ json_encode($change['provenance']) }}</code></td>
                    <td class="p-2">{{ $change['conflict'] ? 'Changed since base' : 'Unchanged since base' }}</td>
                </tr>@endforeach</tbody>
            </table></div>
            @if($refresh->state === \App\Domain\Catalogue\CatalogueProviderRefreshState::Staged)
                <div class="flex flex-wrap gap-6">
                    <form method="POST" action="{{ route('admin.catalogue.provider-refreshes.accept', $refresh) }}" class="space-y-3">
                        @csrf
                        @if($review->stale)<label><input type="checkbox" name="stale_reviewed" value="1" required> I reviewed base, current, proposed values and conflicts</label>@endif
                        <x-primary-button>Accept whole provider proposal</x-primary-button>
                    </form>
                    <form method="POST" action="{{ route('admin.catalogue.provider-refreshes.reject', $refresh) }}" class="space-y-3">
                        @csrf
                        <select name="reason_code" class="rounded dark:bg-gray-800"><option value="insufficient_evidence">Insufficient evidence</option><option value="reviewed">Reviewed · retain current data</option></select>
                        <textarea name="note" maxlength="500" placeholder="Optional private moderator note" class="block rounded dark:bg-gray-800"></textarea>
                        <x-danger-button>Reject provider proposal</x-danger-button>
                    </form>
                </div>
            @endif
        @elseif(in_array($refresh->state, [\App\Domain\Catalogue\CatalogueProviderRefreshState::Queued, \App\Domain\Catalogue\CatalogueProviderRefreshState::Processing], true))
            <p>The fetch is pending. Reload after the queue worker processes it.</p>
        @elseif($refresh->state === \App\Domain\Catalogue\CatalogueProviderRefreshState::NoChange)
            <p>OpenFoodFacts supplied no material changes. No proposal or catalogue version was created.</p>
        @endif
    </div>
</x-app-layout>
