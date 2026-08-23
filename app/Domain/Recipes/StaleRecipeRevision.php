<?php

namespace App\Domain\Recipes;

use RuntimeException;

final class StaleRecipeRevision extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This draft revision is based on an older finalized version and cannot be published.');
    }
}
