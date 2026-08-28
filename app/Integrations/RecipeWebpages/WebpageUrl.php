<?php

namespace App\Integrations\RecipeWebpages;

final readonly class WebpageUrl
{
    public function __construct(
        public string $value,
        public string $scheme,
        public string $host,
        public int $port,
    ) {}
}
