<?php

namespace App\Audit\Enums;

enum AuditRetentionClass: string
{
    case SecurityEventSixMonths = 'security_event_6_months';
    case PrivilegedIdentityTwelveMonths = 'privileged_identity_12_months';
    case ModerationPartyThirtyDays = 'moderation_party_30_days';
    case ModerationDecisionTwelveMonths = 'moderation_decision_12_months';
    case ProvenanceActiveVersionPlusTwelveMonths = 'provenance_active_plus_12_months';
    case PrivateContentUntilFinalPurge = 'private_content_until_final_purge';
    case PurgeReceiptTwelveMonths = 'purge_receipt_12_months';
    case OperationalEvidenceTwelveMonths = 'operational_evidence_12_months';
}
