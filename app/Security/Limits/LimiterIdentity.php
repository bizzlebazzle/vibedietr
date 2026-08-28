<?php

namespace App\Security\Limits;

use Illuminate\Http\Request;

final class LimiterIdentity
{
    public function request(Request $request): string
    {
        $subject = $request->user() === null
            ? 'guest|'.(string) $request->ip()
            : 'user|'.(string) $request->user()->getAuthIdentifier().'|'.(string) $request->ip();

        return hash('sha256', $subject);
    }

    public function input(Request $request, string $input): string
    {
        return hash('sha256', mb_strtolower(trim($input)).'|'.(string) $request->ip());
    }

    public function ip(Request $request): string
    {
        return hash('sha256', 'ip|'.(string) $request->ip());
    }
}
