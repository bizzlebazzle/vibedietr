<?php

namespace App\Integrations\RecipeWebpages;

interface AddressResolver
{
    /** @return list<string> */
    public function resolve(string $host): array;
}
