<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RejectOversizedRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $maximum = (int) config('security.requests.max_bytes');
        $declared = filter_var($request->server('CONTENT_LENGTH'), FILTER_VALIDATE_INT);

        if ($maximum > 0 && is_int($declared) && $declared > $maximum) {
            return $this->tooLarge($request);
        }

        if ($maximum > 0 && ! $request->isMethodSafe()
            && ! str_starts_with((string) $request->header('Content-Type'), 'multipart/form-data')) {
            $content = $request->getContent();
            if (strlen($content) > $maximum) {
                return $this->tooLarge($request);
            }
        }

        return $next($request);
    }

    private function tooLarge(Request $request): Response
    {
        $message = 'The request is too large.';

        return $request->expectsJson()
            ? response()->json(['message' => $message], 413)
            : response($message, 413, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
