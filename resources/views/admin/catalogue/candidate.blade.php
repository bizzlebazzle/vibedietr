<x-app-layout>
    <div class="max-w-6xl mx-auto p-6 space-y-6 text-gray-900 dark:text-gray-100">
        <a class="underline" href="{{ route('admin.catalogue.index', ['type' => 'duplicate_candidate']) }}">Catalogue moderation queue</a>
        <h1 class="text-xl font-semibold">Review duplicate candidate #{{ $record->id }}</h1>
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        <p>Outcome: {{ str_replace('_', ' ', $record->status->value) }} · Detection evidence: {{ str_replace('_', ' ', $record->evidence->value) }}</p>
        <p>Private distinction explanation: {{ $record->distinction_explanation ?? 'None supplied' }}</p>
        <div class="grid md:grid-cols-2 gap-4">
            @foreach ([$record->firstItem, $record->secondItem] as $item)
                <div>@include('admin.catalogue.facts')<p class="mt-2">{{ $counts[$item->id] }} editable recipe matches would move if this identity is merged.</p></div>
            @endforeach
        </div>
        @include('admin.catalogue.authentication')
        @php($actions = $record->status->value === 'pending_review' ? ['distinct', 'dismiss', 'duplicate'] : ($record->status->value === 'confirmed_duplicate' && $record->firstItem->status->value === 'approved' && $record->secondItem->status->value === 'approved' ? ['merge'] : []))
        @foreach ($actions as $action)
            <form method="POST" action="{{ route('admin.catalogue.'.$action, $record) }}" class="p-4 rounded border border-gray-300 dark:border-gray-600 space-y-3">@csrf
                <h2 class="font-semibold">{{ ['distinct' => 'Confirm distinct identities', 'dismiss' => 'Dismiss without identity determination', 'duplicate' => 'Confirm duplicate and choose canonical identity', 'merge' => 'Apply confirmed merge'][$action] }}</h2>
                <input type="hidden" name="revision" value="{{ $record->moderation_revision }}" />
                <input type="hidden" name="first_version_id" value="{{ $record->firstItem->current_catalogue_item_version_id }}" />
                <input type="hidden" name="second_version_id" value="{{ $record->secondItem->current_catalogue_item_version_id }}" />
                @include('admin.catalogue.reason', ['formKey' => $action])
                @if ($action === 'duplicate')
                    <fieldset class="space-y-2"><legend>Explicitly choose the canonical identity</legend>
                        @foreach ([$record->firstItem, $record->secondItem] as $item)
                            <label class="block"><input type="radio" name="canonical_id" value="{{ $item->id }}" required @checked((string) old('canonical_id') === (string) $item->id) /> Keep #{{ $item->id }} · {{ $item->currentVersion?->name }} canonical</label>
                        @endforeach
                    </fieldset>
                    <label class="block"><input type="checkbox" name="identity_reviewed" value="1" required /> I reviewed core identity and nutrition-basis evidence and found no unresolved material contradiction. Name similarity alone is insufficient.</label>
                @elseif ($action === 'merge')
                    @php($canonical = $record->canonical_catalogue_item_id === $record->firstItem->id ? $record->firstItem : $record->secondItem)
                    @php($source = $canonical->id === $record->firstItem->id ? $record->secondItem : $record->firstItem)
                    <input type="hidden" name="canonical_id" value="{{ $canonical->id }}" />
                    <p>Merge #{{ $source->id }} · {{ $source->currentVersion?->name }} into #{{ $canonical->id }} · {{ $canonical->currentVersion?->name }}.</p>
                    <p>{{ $counts[$source->id] }} editable recipe matches move to the canonical current version. Historical snapshots, original ingredient text, versions and provenance remain unchanged. Existing redirects will point directly to the canonical identity.</p>
                    <label class="block"><input type="checkbox" name="exclude_primary_alias" value="1" /> Exclude the source’s former primary name from canonical search aliases</label>
                    <fieldset><legend>Other aliases to explicitly approve for the canonical identity</legend>
                        @forelse ($source->aliases->whereNull('disabled_at') as $alias)<label class="block"><input type="checkbox" name="alias_ids[]" value="{{ $alias->id }}" /> {{ $alias->alias }}</label>@empty<p>No other approved aliases.</p>@endforelse
                    </fieldset>
                    <label class="block"><input type="checkbox" name="confirm_merge" value="1" required /> I confirm this source, canonical identity and reference migration.</label>
                @endif
                <x-primary-button>{{ ['distinct' => 'Mark distinct', 'dismiss' => 'Dismiss candidate', 'duplicate' => 'Confirm duplicate', 'merge' => 'Apply merge'][$action] }}</x-primary-button>
            </form>
        @endforeach
        @include('admin.catalogue.history')
    </div>
</x-app-layout>
