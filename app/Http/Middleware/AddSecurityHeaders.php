<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

final class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        Vite::useCspNonce();
        $response = $next($request);
        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=(), usb=()');
        $response->headers->set('X-Frame-Options', 'DENY');

        if (app()->environment('production') && $request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age='.(int) config('security.headers.hsts_max_age').'; includeSubDomains',
            );
        }

        if ($this->isSensitive($request)) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        $nonce = Vite::cspNonce();
        $script = ["'self'", "'nonce-{$nonce}'", "'unsafe-eval'"];
        $connect = ["'self'"];

        if (app()->environment('local')) {
            $developmentServer = rtrim((string) config('security.headers.csp.development_server'), '/');
            if (filter_var($developmentServer, FILTER_VALIDATE_URL) !== false) {
                $script[] = $developmentServer;
                $connect[] = $developmentServer;
                $connect[] = preg_replace('~^http~', 'ws', $developmentServer) ?: $developmentServer;
            }
        }

        $directives = [
            "default-src 'self'",
            'script-src '.implode(' ', array_unique($script)),
            "style-src 'self' 'nonce-{$nonce}'",
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "media-src 'self' blob:",
            'connect-src '.implode(' ', array_unique($connect)),
            "worker-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ];

        if (app()->environment('production')) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }

    private function isSensitive(Request $request): bool
    {
        return $request->is(
            'login', 'register', 'forgot-password*', 'reset-password*',
            'confirm-password', 'verify-email*', 'profile*', 'security/*',
            'recipe-imports*',
        );
    }
}
