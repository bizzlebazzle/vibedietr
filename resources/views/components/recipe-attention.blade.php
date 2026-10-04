@props(['issues', 'id' => 'nutrition-attention', 'editUrl' => null])
<aside class="nutrition-warning" aria-labelledby="{{ $id }}-heading">
    <h3 id="{{ $id }}-heading" class="font-semibold">Ingredients needing attention: {{ count($issues) }}</h3>
    @if ($issues !== [])
        <details class="mt-2">
            <summary class="inline-flex min-h-11 cursor-pointer items-center font-semibold">Show affected ingredients and remedies</summary>
            <ol class="mt-3 list-decimal space-y-3 pl-5">
                @foreach ($issues as $issue)
                    <li>
                        <p class="font-medium">Ingredient {{ $issue['position'] + 1 }}: {{ $issue['original_text'] }}</p>
                        @if ($issue['selected_food'] !== null)<p>Selected food: {{ $issue['selected_food'] }}</p>@endif
                        <ul class="mt-1 list-disc space-y-1 pl-5">
                            @foreach ($issue['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach
                        </ul>
                        <p class="mt-1">{{ implode(' ', $issue['remedies']) }}</p>
                        @if ($editUrl !== null)
                            <a href="{{ $editUrl }}#ingredient-line-{{ $issue['position'] + 1 }}" data-review-link class="mt-2 inline-flex min-h-11 items-center font-semibold text-blue-800 underline dark:text-blue-200">Review or correct ingredient {{ $issue['position'] + 1 }}<span class="sr-only">: {{ $issue['original_text'] }}</span></a>
                        @endif
                    </li>
                @endforeach
            </ol>
        </details>
    @endif
</aside>
