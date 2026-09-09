<?php

namespace App\Domain\Catalogue;

enum ManualCatalogueSubmissionChoice: string
{
    case Reuse = 'reuse';
    case ContinueDistinct = 'continue_distinct';
}
