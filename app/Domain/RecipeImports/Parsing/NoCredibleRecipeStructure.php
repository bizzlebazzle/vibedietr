<?php

namespace App\Domain\RecipeImports\Parsing;

use RuntimeException;

final class NoCredibleRecipeStructure extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No credible recipe structure was found.');
    }
}
