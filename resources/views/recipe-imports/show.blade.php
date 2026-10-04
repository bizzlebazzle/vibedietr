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
                        'recipe_structure_not_found', 'multiple_recipe_candidates' => 'No single usable recipe structure could be found in this source.',
                        'image_too_large', 'stored_source_size_invalid' => 'The image is too large.',
                        'image_pixel_limit' => 'The image exceeds the 50-megapixel limit.',
                        'multiple_image_frames' => 'This image contains multiple frames and cannot be imported.',
                        'image_type_mismatch', 'binary_text_content', 'unsupported_text_encoding', 'corrupt_or_unsupported_image' => 'This file type or content is not supported.',
                        'no_usable_ocr_text' => 'OCR failed: no usable text was recovered. No reviewable draft was created.',
                        'tesseract_timeout', 'tesseract_execution_failed' => 'Import processing failed. You can upload the source again.',
                        'abandoned_input_expired' => 'The transient upload expired before it could be processed.',
                        default => 'This source could not be imported.',
                    };
                @endphp
                <div role="alert" class="rounded border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/30 dark:text-red-100">{{ $safeMessage }} No recipe was published.</div>
                @can('retry', $import)
                    <p class="text-sm">Retry this import using the same saved source. Retrying may still fail if the source is unsupported or cannot be parsed.</p>
                    <form method="POST" action="{{ route('recipe-imports.retry', $import) }}">@csrf<x-primary-button>Retry import</x-primary-button></form>
                @else
                    @if (in_array($import->type->value, ['pasted_text', 'webpage_url'], true))<p class="text-sm">The retry limit for this import has been reached. You can edit the source and submit a new import, or create a recipe manually.</p>@endif
                @endcan
                @if (in_array($import->type->value, ['uploaded_text', 'uploaded_image'], true))
                    <p class="text-sm">Uploads are transient and deleted after processing. After terminal cleanup, retrying {{ $import->type->value === 'uploaded_image' ? 'OCR' : 'document extraction' }} requires you to upload the source again. Any recovered text shown below remains available for manual recovery.</p>
                @else
                    <p class="text-sm">Your saved source is still shown below where available. You can copy it into a new pasted-text import or use it to create a recipe manually.</p>
                @endif
                <div class="flex flex-wrap gap-4 text-sm font-semibold text-blue-700 dark:text-blue-300">
                    @can('create', \App\Models\Recipe::class)<a href="{{ route('recipes.create') }}" class="inline-flex min-h-11 items-center underline">Create a recipe manually</a>@endcan
                    @can('create', \App\Models\RecipeImport::class)
                        @if (in_array($import->type->value, ['uploaded_text', 'uploaded_image'], true))
                            <a href="{{ route('recipe-imports.create') }}" class="inline-flex min-h-11 items-center underline">Upload source again</a>
                        @endif
                        <a href="{{ route('recipe-imports.create') }}#pasted-text-import" class="inline-flex min-h-11 items-center underline">Paste recipe text instead</a>
                    @endcan
                </div>
            @endif
            @if (! $import->status->terminal())
                <p class="text-sm">Processing runs in the background. Refresh this page to check progress; you do not need to submit the source again while it is processing.</p>
            @endif
            @if ($import->status->value === 'review_ready')
                <p class="text-sm">Review the title, servings, ingredient wording, food matches, and instructions in the private draft. Fill in missing content and check nutrition limitations before finalizing. Drafts cannot be added to meal plans.</p>
            @endif
            @include('recipe-imports.partials.ocr-guidance')
            @if ($import->type->value === 'webpage_url')
                <dl class="grid gap-2 text-sm sm:grid-cols-2"><div><dt class="font-medium">Submitted source</dt><dd class="break-all">{{ $import->submitted_url }}</dd></div>@if ($import->final_url)<div><dt class="font-medium">Final source after redirects</dt><dd class="break-all">{{ $import->final_url }}</dd></div>@endif @if ($import->extraction_method)<div><dt class="font-medium">Extraction method</dt><dd>{{ str_replace('_', ' ', $import->extraction_method) }}</dd></div>@endif</dl>
            @endif
            @if ($import->parser_identifier)<dl class="grid gap-2 text-sm sm:grid-cols-2"><div><dt class="font-medium">Parser</dt><dd>{{ $import->parser_identifier }} {{ $import->parser_version }}</dd></div><div><dt class="font-medium">Review state</dt><dd>Needs review</dd></div></dl>@endif
            @if (($import->warnings ?? []) !== [])<div><h4 class="font-medium">Warnings</h4><ul class="mt-2 list-disc pl-5 text-sm">@foreach ($import->warnings as $warning)<li>{{ str_replace('_', ' ', ucfirst($warning)) }}</li>@endforeach</ul></div>@endif
        </section>
        @if ($import->source_text !== null)
        <details class="rounded-lg bg-white p-6 shadow dark:bg-slate-900">
            <summary class="cursor-pointer font-semibold">{{ $import->type->value === 'uploaded_image' ? 'Recovered OCR text' : ($import->type->value === 'webpage_url' || $import->type->value === 'uploaded_text' ? 'Extracted recipe source' : 'Original pasted source') }}</summary>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $import->type->value === 'uploaded_image' ? 'Recovered from the image by OCR; it may be inaccurate and is not an exact transcription.' : ($import->type->value === 'pasted_text' ? 'Preserved exactly as accepted; parsed suggestions do not replace it.' : 'Extracted locally; the complete webpage or uploaded file is transient.') }}</p>
            <pre class="mt-4 max-h-[40rem] overflow-auto whitespace-pre-wrap rounded bg-gray-100 p-4 text-sm text-gray-900 dark:bg-slate-950 dark:text-slate-100">{{ $import->source_text }}</pre>
        </details>
        @endif
    </div></div>
</x-app-layout>
