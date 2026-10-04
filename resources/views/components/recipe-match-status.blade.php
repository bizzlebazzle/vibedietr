@props(['match' => null, 'original', 'id', 'creator' => false])
<div id="{{ $id }}" tabindex="-1" data-ingredient-review class="recipe-match-status whitespace-normal {{ ($match['unavailable'] ?? false) ? 'nutrition-unavailable' : (($match['review_state'] ?? null) === 'needs_review' ? 'nutrition-warning' : 'rounded border border-gray-200 p-3 text-sm dark:border-slate-600') }}">
    <p class="font-semibold">{{ app(\App\Domain\Recipes\RecipeIngredientMatchPresenter::class)->label($match, $creator) }}</p>
    @if ($match !== null)
        <p class="mt-1">Selected food: <strong>{{ $match['name'] ?? 'Name unavailable' }}</strong></p>
        @if ($match['unavailable'] ?? false)
            <p class="mt-1">The original ingredient text is unchanged. No replacement has been applied automatically.</p>
        @elseif (($match['review_state'] ?? null) === 'needs_review')
            <p class="mt-1">This automatic match has weaker matching evidence. Check that {{ $match['name'] ?? 'the selected food' }} fits the original ingredient: {{ $original }}</p>
            <p class="mt-1">This selection remains included wherever nutrition can be calculated. Review is optional; it does not verify nutrition or fix other limitations.</p>
        @else
            <p class="mt-1">Selection does not mean verified nutrition.{{ $creator ? ' You can search to replace or clear this match.' : '' }}</p>
        @endif
    @else
        <p class="mt-1">{{ $creator ? 'Search the catalogue to select a food for this ingredient.' : 'The creator can select a food in the ingredient editor.' }}</p>
    @endif
</div>
