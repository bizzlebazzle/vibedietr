<?php

namespace App\Http\Middleware;

use App\Security\SecondFactor\PrivilegedWorkflowGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequirePrivilegedAuthentication
{
    public function __construct(private readonly PrivilegedWorkflowGuard $guard) {}

    public function handle(Request $request, Closure $next, string $operation): Response
    {
        abort_unless($this->guard->allows($request->user(), $operation, $request->session()), 403);

        return $next($request);
    }
}
