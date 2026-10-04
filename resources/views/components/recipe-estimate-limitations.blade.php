@props(['estimate', 'id' => 'nutrition-limitations', 'editUrl' => null])
<section aria-labelledby="{{ $id }}-heading">
    <h3 id="{{ $id }}-heading" class="font-semibold">{{ $estimate['status'] === 'complete' ? 'Complete estimate' : 'Estimate limitations' }}</h3>
    <p class="mt-1 text-sm">
        @if ($estimate['status'] === 'complete')
            Complete estimate: every ingredient line contributed and none requires review. Values remain estimates.
        @elseif ($estimate['status'] === 'unavailable')
            No nutrition values are currently available for this recipe estimate. Missing values are not zero.
        @else
            This is a partial estimate. Available values remain useful, but some ingredients are excluded from some or all calculations or need review. Missing values are not zero.
        @endif
    </p>
    @if ($estimate['issues'] !== [])
        <div class="mt-3"><x-recipe-attention :issues="$estimate['issues']" :id="$id.'-attention'" :edit-url="$editUrl" /></div>
    @endif
</section>
