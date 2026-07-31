<?php

namespace App\Audit\Enums;

enum AuditPurpose: string
{
    case AccountSecurity = 'account_security';
    case PrivilegedAccessAccountability = 'privileged_access_accountability';
    case ModerationAccountability = 'moderation_accountability';
    case CatalogueProvenance = 'catalogue_provenance';
    case ProductHistory = 'product_history';
    case AccountErasureEvidence = 'account_erasure_evidence';
    case OperationalAccountability = 'operational_accountability';

    public function isAdministratorReadable(): bool
    {
        return in_array($this, [
            self::ModerationAccountability,
            self::CatalogueProvenance,
        ], true);
    }
}
