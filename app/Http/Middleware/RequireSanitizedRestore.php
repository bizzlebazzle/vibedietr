<?php

namespace App\Http\Middleware;

use App\Domain\AccountDeletion\RestoreReleaseCertificate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class RequireSanitizedRestore
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            app(RestoreReleaseCertificate::class)->assertReleased();
        } catch (Throwable) {
            return response('Restoration verification is required.', 503, ['Cache-Control' => 'no-store']);
        }

        return $next($request);
    }
}
