<?php

namespace App\Domain\Catalogue;

enum CatalogueChangeProposalType: string
{
    case UserCorrection = 'user_correction';
    case ProviderRefresh = 'provider_refresh';
}
