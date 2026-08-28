<?php

namespace App\Integrations\RecipeWebpages;

final readonly class ValidatedDestination
{
    public function __construct(
        public WebpageUrl $url,
        public string $address,
    ) {}
}
