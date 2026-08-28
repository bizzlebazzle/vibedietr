<?php

namespace App\Integrations\RecipeWebpages;

final readonly class FetchedWebpage
{
    public function __construct(
        public string $submittedUrl,
        public string $finalUrl,
        public string $html,
        public int $redirectCount,
    ) {}
}
