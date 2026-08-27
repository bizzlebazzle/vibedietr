<?php

namespace App\Http\Middleware;

use App\Audit\AuditReferenceValidator;
use App\Observability\CorrelationContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final class AttachCorrelationId
{
    public function __construct(private readonly CorrelationContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $candidate = $request->headers->get('X-Correlation-ID');

        try {
            $validated = is_string($candidate)
                ? AuditReferenceValidator::validate($candidate, 'correlation identifier')
                : null;
        } catch (InvalidArgumentException) {
            $validated = null;
        }

        $correlationId = $this->context->set($validated);
        $request->attributes->set('correlation_id', $correlationId);
        Log::withContext(['correlation_id' => $correlationId]);

        $response = $next($request);
        $response->headers->set('X-Correlation-ID', $correlationId);

        return $response;
    }
}
