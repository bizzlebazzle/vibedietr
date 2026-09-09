<x-app-layout>
    <div class="max-w-4xl mx-auto p-6 space-y-6 text-gray-900 dark:text-gray-100">
        <a class="underline" href="{{ route('admin.catalogue.index') }}">Catalogue moderation queue</a>
        <h1 class="text-xl font-semibold">Review manual submission</h1>
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        @include('admin.catalogue.facts', ['item' => $record])
        <p>Submitter: {{ $record->submitter?->name ?? 'Removed contributor' }}</p>
        <section><h2 class="font-semibold">Duplicate candidates</h2>
            @forelse ($candidates as $candidate)<p><a class="underline" href="{{ route('admin.catalogue.candidate', $candidate) }}">Pair #{{ $candidate->id }} · {{ $candidate->status->value }}</a></p>@empty<p>No candidate pairs.</p>@endforelse
            {{ $candidates->links() }}
        </section>
        @if ($record->status->value === 'pending' && $record->currentVersion)
            @include('admin.catalogue.authentication')
            @foreach (['approve', 'reject'] as $action)
                <form method="POST" action="{{ route('admin.catalogue.'.$action, $record) }}" class="p-4 rounded border border-gray-300 dark:border-gray-600 space-y-3">@csrf
                    <h2 class="font-semibold">{{ ucfirst($action) }} submission</h2>
                    <input type="hidden" name="revision" value="{{ $record->moderation_revision }}" />
                    <input type="hidden" name="version_id" value="{{ $record->current_catalogue_item_version_id }}" />
                    @include('admin.catalogue.reason', ['formKey' => $action])
                    @if ($action === 'reject')
                        <p>Rejection retains a non-selectable tombstone. Existing recipe matches will not be replaced.</p>
                        <x-input-label for="replacement" value="Suggested approved catalogue ID (optional; required for duplicate rejection)" /><x-text-input id="replacement" name="replacement_id" inputmode="numeric" :value="old('replacement_id')" />
                        <x-danger-button>Reject submission</x-danger-button>
                    @else
                        <p>Approval makes this identity and its existing version available in the shared catalogue.</p>
                        <x-primary-button>Approve submission</x-primary-button>
                    @endif
                </form>
            @endforeach
        @endif
        @include('admin.catalogue.history')
    </div>
</x-app-layout>
