<?php

namespace App\Domain\Catalogue;

enum ManualCatalogueSubmissionStatus: string
{
    case ChoiceRequired = 'choice_required';
    case Reused = 'reused';
    case Created = 'created';
}
