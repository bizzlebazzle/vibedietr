<x-app-layout>
    <div class="max-w-4xl mx-auto p-6 space-y-6 text-gray-900 dark:text-gray-100">
        <a class="underline" href="{{ route('admin.catalogue.index') }}">Catalogue moderation queue</a>
        <h1 class="text-xl font-semibold">Moderation decision: {{ $record->action }}</h1>
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        <p>{{ $record->id }} · {{ $record->created_at->utc()->toIso8601String() }}</p>
        <p>Catalogue identity #{{ $record->catalogue_item_id }} @if ($record->canonical_catalogue_item_id) · Canonical identity #{{ $record->canonical_catalogue_item_id }} @endif</p>
        <p>Reason: {{ $record->reason_code }}</p>
        <p class="break-words whitespace-pre-wrap">Private note: {{ $record->note ?? 'None' }}</p>
        @if ($record->corrects_decision_id)<p>Corrects <a class="underline" href="{{ route('admin.catalogue.decision', $record->corrects_decision_id) }}">{{ $record->corrects_decision_id }}</a>.</p>@endif
        @if ($record->candidate_id)<p><a class="underline" href="{{ route('admin.catalogue.candidate', $record->candidate_id) }}">Review candidate and its complete decision history</a></p>@endif
        @foreach ($moves as $move)<p>{{ $move->total }} recorded {{ str_replace('_', ' ', $move->reference_type) }} movements</p>@endforeach
        @if ($record->action === 'correct')<p>Safe references restored: {{ $record->evidence['restored_count'] ?? 0 }}. Later choices or historical references preserved: {{ $record->evidence['preserved_count'] ?? 0 }}.</p>@endif
        @if ($correction)
            <p>This decision was corrected by <a class="underline" href="{{ route('admin.catalogue.decision', $correction) }}">{{ $correction->id }}</a>.</p>
        @elseif ($record->action !== 'correct')
            @include('admin.catalogue.authentication')
            <form method="POST" action="{{ route('admin.catalogue.correct', $record) }}" class="space-y-3">@csrf
                <h2 class="font-semibold">Review a correction</h2>
                <p>Corrections append a new decision. An eligible merge restores safe recorded movements and the source identity. Subsequent merges or changed catalogue versions require further review. Historical snapshots remain unchanged.</p>
                @include('admin.catalogue.reason', ['formKey' => 'correct'])
                <label class="block"><input type="checkbox" name="preserve_later_choices" value="1" /> I reviewed the conflict policy: preserve later owner choices, removed matches and references that became historical; restore only unchanged eligible references.</label>
                <label class="block"><input type="checkbox" name="confirm_correction" value="1" required /> I confirm this correction of decision {{ $record->id }}.</label>
                <x-danger-button>Record correction</x-danger-button>
            </form>
        @endif
    </div>
</x-app-layout>
