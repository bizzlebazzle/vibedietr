<?php

namespace App\Integrations\RecipeWebpages;

interface WebpageTransport
{
    public function request(ValidatedDestination $destination): WebpageResponse;
}
