@if ($import)
    <section class="mb-6 space-y-4 rounded-lg border border-amber-300 bg-amber-50 p-5 text-amber-950 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100" aria-labelledby="import-review-heading">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 id="import-review-heading" class="font-semibold">Imported — needs review</h3>
                <p class="mt-1 text-sm">Suggestions from {{ $import->parser_identifier ?? 'the local parser' }}{{ $import->parser_version ? ' '.$import->parser_version : '' }} are not authoritative. Edit the draft normally, then finalize it explicitly when ready.</p>
            </div>
            <a href="{{ route('recipe-imports.show', $import) }}" class="rounded border border-amber-500 px-3 py-2 text-sm font-semibold">View import source</a>
        </div>

        @php
            $reviewIngredients = $recipe->ingredientLines()->where('requires_review', true)->get();
            $reviewSteps = $recipe->instructionSteps()->where('requires_review', true)->get();
        @endphp
        @if (($import->warnings ?? []) !== [] || $reviewIngredients->isNotEmpty() || $reviewSteps->isNotEmpty())
            <div class="text-sm">
                <p class="font-medium">Check these parser suggestions:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach (($import->warnings ?? []) as $warning)<li>{{ str_replace('_', ' ', ucfirst($warning)) }}</li>@endforeach
                    @foreach ($reviewIngredients as $line)<li>Ingredient: {{ $line->original_text }} — {{ collect($line->parser_warnings ?? [])->map(fn ($warning) => str_replace('_', ' ', $warning))->join(', ') }}</li>@endforeach
                    @foreach ($reviewSteps as $step)<li>Instruction: {{ $step->text }} — {{ collect($step->parser_warnings ?? [])->map(fn ($warning) => str_replace('_', ' ', $warning))->join(', ') }}</li>@endforeach
                </ul>
            </div>
        @endif
    </section>
@endif
