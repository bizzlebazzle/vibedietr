<?php

namespace App\Domain\Catalogue;

enum CatalogueDuplicateEvidence: string
{
    case ExactPrimaryName = 'exact_primary_name';
    case ApprovedAlias = 'approved_alias';
    case FuzzySuggestion = 'fuzzy_suggestion';
}
