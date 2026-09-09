<section class="space-y-2">
    <h2 class="font-semibold">Decision history</h2>
    @forelse ($history as $decision)
        <p><a class="underline" href="{{ route('admin.catalogue.decision', $decision) }}">{{ $decision->created_at->utc()->toIso8601String() }} · {{ $decision->action }} · {{ $decision->reason_code }}</a></p>
    @empty <p>No moderation decisions yet.</p>@endforelse
    {{ $history->links() }}
</section>
