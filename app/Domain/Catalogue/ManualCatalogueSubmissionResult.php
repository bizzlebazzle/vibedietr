<?php

namespace App\Domain\Catalogue;

use App\Models\CatalogueItem;

final readonly class ManualCatalogueSubmissionResult
{
    /**
     * @param  list<ManualCatalogueDuplicateMatch>  $strongMatches
     * @param  list<ManualCatalogueDuplicateMatch>  $fuzzySuggestions
     */
    public function __construct(
        public ManualCatalogueSubmissionStatus $status,
        public ?CatalogueItem $item,
        public array $strongMatches = [],
        public array $fuzzySuggestions = [],
    ) {}
}
