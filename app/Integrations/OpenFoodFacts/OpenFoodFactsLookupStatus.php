<?php

namespace App\Integrations\OpenFoodFacts;

enum OpenFoodFactsLookupStatus: string
{
    case Success = 'success';
    case NotFound = 'not_found';
    case Unavailable = 'unavailable';
    case RateLimited = 'rate_limited';
    case InvalidResponse = 'invalid_response';
    case PermanentFailure = 'permanent_failure';
}
