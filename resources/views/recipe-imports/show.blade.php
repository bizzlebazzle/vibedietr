<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800 dark:text-slate-100">Recipe import</h2></x-slot>
    <div class="py-12"><div class="mx-auto max-w-3xl space-y-6 sm:px-6 lg:px-8">
        @if (session('status'))<x-auth-session-status :status="session('status')" />@endif
        <section class="space-y-4 rounded-lg bg-white p-6 shadow dark:bg-slate-900">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><h3 class="font-semibold text-gray-900 dark:text-slate-100">Status: {{ str_replace('_', ' ', ucfirst($import->status->value)) }}</h3><p class="text-sm text-gray-600 dark:text-gray-400">This import and its source are visible only to you.</p></div>
                @if ($import->status->value === 'review_ready' && $import->recipe)<a href="{{ route('recipes.edit', $import->recipe) }}" class="rounded bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Review draft</a>@endif
            </div>
            @if ($import->status->value === 'failed')
                @php
                    $safeMessage = match ($import->failure_code) {
                        'response_too_large' => 'The page was too large to process.',
                        'unsupported_content_type' => 'This page type is not supported.',
                        'non_public_destination' => 'Only public HTTP/HTTPS recipe pages are supported.',
                        'recipe_structure_not_found', 'multiple_recipe_candidates' => 'No single usable recipe could be found on that page.',
                        default => 'That page could not be imported.',
                    };
                @endphp
                <div role="alert" class="rounded border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/30 dark:text-red-100">{{ $safeMessage }} No recipe was published.</div>
                @can('retry', $import)<form method="POST" action="{{ route('recipe-imports.retry', $import) }}">@csrf<x-primary-button>Retry import</x-primary-button></form>@endcan
            @endif
            @if ($import->type->value === 'webpage_url')
                <dl class="grid gap-2 text-sm sm:grid-cols-2"><div><dt class="font-medium">Submitted source</dt><dd class="break-all">{{ $import->submitted_url }}</dd></div>@if ($import->final_url)<div><dt class="font-medium">Final source after redirects</dt><dd class="break-all">{{ $import->final_url }}</dd></div>@endif @if ($import->extraction_method)<div><dt class="font-medium">Extraction method</dt><dd>{{ str_replace('_', ' ', $import->extraction_method) }}</dd></div>@endif</dl>
            @endif
            @if ($import->parser_identifier)<dl class="grid gap-2 text-sm sm:grid-cols-2"><div><dt class="font-medium">Parser</dt><dd>{{ $import->parser_identifier }} {{ $import->parser_version }}</dd></div><div><dt class="font-medium">Review state</dt><dd>Needs review</dd></div></dl>@endif
            @if (($import->warnings ?? []) !== [])<div><h4 class="font-medium">Warnings</h4><ul class="mt-2 list-disc pl-5 text-sm">@foreach ($import->warnings as $warning)<li>{{ str_replace('_', ' ', ucfirst($warning)) }}</li>@endforeach</ul></div>@endif
        </section>
        @if ($import->source_text !== null)
        <details class="rounded-lg bg-white p-6 shadow dark:bg-slate-900">
            <summary class="cursor-pointer font-semibold">{{ $import->type->value === 'webpage_url' ? 'Extracted recipe source' : 'Original pasted source' }}</summary>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $import->type->value === 'webpage_url' ? 'Extracted locally from the source page; the complete fetched HTML was not retained.' : 'Preserved exactly as accepted; parsed suggestions do not replace it.' }}</p>
            <pre class="mt-4 max-h-[40rem] overflow-auto whitespace-pre-wrap rounded bg-gray-100 p-4 text-sm text-gray-900 dark:bg-slate-950 dark:text-slate-100">{{ $import->source_text }}</pre>
        </details>
        @endif
    </div></div>
</x-app-layout>
