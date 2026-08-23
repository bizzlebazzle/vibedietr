<?php

namespace App\Http\Controllers;

use App\Domain\Profiles\PublicProfileReader;
use Illuminate\Contracts\View\View;

class PublicProfileController extends Controller
{
    public function __invoke(string $publicProfile, PublicProfileReader $reader): View
    {
        return view('public-profiles.show', [
            'profile' => $reader->findEnabled($publicProfile),
        ]);
    }
}
