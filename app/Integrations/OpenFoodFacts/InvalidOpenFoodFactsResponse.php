<?php

namespace App\Integrations\OpenFoodFacts;

use RuntimeException;

final class InvalidOpenFoodFactsResponse extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The provider returned an invalid response.');
    }
}
