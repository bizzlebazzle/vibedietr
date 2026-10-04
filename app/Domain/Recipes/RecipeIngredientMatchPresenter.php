<?php

namespace App\Domain\Recipes;

final class RecipeIngredientMatchPresenter
{
    /** @param array<string, mixed>|null $match */
    public function label(?array $match, bool $creator = false): string
    {
        if ($match === null) {
            return 'No food selected — excluded from estimate';
        }
        if ($match['unavailable'] ?? false) {
            return 'Selected food unavailable — choose an approved replacement or clear match';
        }
        if (($match['review_state'] ?? null) === 'needs_review') {
            return 'Selected — needs review';
        }
        if (($match['provenance'] ?? null) === 'automatically_selected') {
            return ($match['confidence_band'] ?? null) === 'reviewable'
                ? ($creator ? 'Reviewed by you' : 'Reviewed by creator')
                : 'Automatically selected';
        }

        return $creator ? 'Selected by you' : 'Selected by creator';
    }
}
