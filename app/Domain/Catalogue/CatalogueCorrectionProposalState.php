<?php

namespace App\Domain\Catalogue;

enum CatalogueCorrectionProposalState: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
