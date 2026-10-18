<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-gray-800 dark:text-slate-100">Account data export</h1></x-slot>
    <div class="mx-auto max-w-3xl space-y-6 px-4 py-8 text-gray-900 dark:text-slate-100">
        <p>Download your account data, owned recipes and history, plans, diary, targets and proposals as a documented JSON ZIP. Other users' private accounts are excluded. Calculated nutrition remains an estimate.</p>
        <p>Exports expire seven days after the request. An unexpired export is reused. Keep downloaded copies private. This download does not provide application restore or guaranteed third-party import.</p>
        @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
        <form method="POST" action="{{ route('account-exports.store') }}">@csrf<x-primary-button>Request export</x-primary-button></form>
        <h2 class="text-lg font-semibold">Recent exports</h2>
        <ul class="space-y-4">
            @forelse ($exports as $export)
                <li class="rounded border border-gray-300 p-4 dark:border-slate-600">
                    <p>Requested {{ $export->created_at->utc()->format('Y-m-d H:i') }} UTC</p>
                    @if ($export->expires_at->isPast() || in_array($export->state, ['expired', 'deleted']))
                        <p>Expired. Request a new export.</p>
                    @elseif ($export->state === 'ready')
                        <p>Ready. Expires {{ $export->expires_at->utc()->format('Y-m-d H:i') }} UTC.</p>
                        <a class="underline" href="{{ route('account-exports.download', $export) }}">Download export requested {{ $export->created_at->utc()->format('Y-m-d H:i:s') }} UTC</a>
                    @elseif ($export->state === 'failed')
                        <p>Generation failed. You can request another export. Contact support if this continues.</p>
                    @else
                        <p role="status">Queued for generation. Refresh to check progress.</p>
                    @endif
                </li>
            @empty
                <li>No exports requested yet.</li>
            @endforelse
        </ul>
        <a class="underline" href="{{ route('profile') }}">Back to profile</a>
    </div>
</x-app-layout>
