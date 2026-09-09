<?php

namespace App\Domain\Catalogue;

enum CatalogueDuplicateCandidateStatus: string
{
    case PendingReview = 'pending_review';
    case ConfirmedDistinct = 'confirmed_distinct';
    case ConfirmedDuplicate = 'confirmed_duplicate';
    case Dismissed = 'dismissed';
}
