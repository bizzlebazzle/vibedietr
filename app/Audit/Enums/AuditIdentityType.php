<?php

namespace App\Audit\Enums;

enum AuditIdentityType: string
{
    case User = 'user';
    case ExternalOperator = 'external_operator';
    case Deployment = 'deployment';
}
