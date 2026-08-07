<?php

namespace App\Queue;

use App\Audit\AuditReferenceValidator;
use Illuminate\Support\Str;

final class CorrelationId
{
    public static function resolve(?string $correlationId): string
    {
        if ($correlationId === null) {
            return (string) Str::ulid();
        }

        return AuditReferenceValidator::validate($correlationId, 'correlation identifier')
            ?? throw new \InvalidArgumentException('A correlation identifier is required.');
    }
}
