<?php

namespace App\Domain\Recipes;

use DomainException;

final class InvalidOriginalRecipeServings extends DomainException
{
    public function __construct()
    {
        parent::__construct('This recipe does not have a valid positive saved serving count, so its quantities cannot be resized.');
    }
}
