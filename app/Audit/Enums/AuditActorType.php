<?php

namespace App\Audit\Enums;

enum AuditActorType: string
{
    case AuthenticatedUser = 'authenticated_user';
    case Administrator = 'administrator';
    case System = 'system';
    case ExternalOperator = 'external_operator';
    case Deployment = 'deployment';
}
