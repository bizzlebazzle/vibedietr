<x-app-layout>
    <x-slot name="header"><h1 class="font-semibold text-xl text-gray-800 dark:text-gray-200">Catalogue moderation</h1></x-slot>
    <div class="max-w-6xl mx-auto p-6 space-y-6 text-gray-900 dark:text-gray-100">
        <p>Review manual submissions and possible duplicates. Moderation details are private to administrators.</p>
        <x-input-error :messages="$errors->all()" />
        <form method="GET" class="flex flex-wrap gap-4 items-end">
            <div><x-input-label for="type" value="Work type" /><select id="type" name="type" class="rounded dark:bg-gray-800">
                <option value="manual_submission" @selected($type === 'manual_submission')>Manual submission</option>
                <option value="duplicate_candidate" @selected($type === 'duplicate_candidate')>Duplicate candidate</option>
            </select></div>
            <div><x-input-label for="state" value="State" /><select id="state" name="state" class="rounded dark:bg-gray-800">
                <option value="">All states</option>
                @foreach (\App\Domain\Catalogue\CatalogueModerationQueue::STATES as $workType => $states)
                    <optgroup label="{{ str_replace('_', ' ', $workType) }}">
                        @foreach ($states as $value)<option value="{{ $value }}" @selected($state === $value)>{{ str_replace('_', ' ', $value) }}</option>@endforeach
                    </optgroup>
                @endforeach
            </select></div>
            <x-primary-button>Filter</x-primary-button>
        </form>
        <p>{{ $rows->total() }} matching {{ $rows->total() === 1 ? 'record' : 'records' }}</p>
        <div class="space-y-3">
            @forelse ($rows as $row)
                <article class="p-4 rounded border border-gray-300 dark:border-gray-600 break-words">
                    @if ($type === 'manual_submission')
                        <a class="underline font-semibold" href="{{ route('admin.catalogue.submission', $row) }}">#{{ $row->id }} · {{ $row->currentVersion?->name ?? 'Catalogue submission' }}</a>
                    @else
                        <a class="underline font-semibold" href="{{ route('admin.catalogue.candidate', $row) }}">#{{ $row->id }} · {{ $row->firstItem->currentVersion?->name ?? 'First identity' }} / {{ $row->secondItem->currentVersion?->name ?? 'Second identity' }}</a>
                        @if ($row->firstItem->status->value === 'merged' || $row->secondItem->status->value === 'merged')<p>A merge has been applied. Inspect decision history.</p>@endif
                    @endif
                    <p>{{ str_replace('_', ' ', $row->status->value) }}</p>
                </article>
            @empty
                <p>No moderation work matches these filters.</p>
            @endforelse
        </div>
        {{ $rows->links() }}
    </div>
</x-app-layout>
