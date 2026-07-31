<?php

namespace App\Audit\Enums;

enum AuditAction: string
{
    case AdministratorBootstrapCompleted = 'administrator.bootstrap_completed';
    case AdministratorBootstrapRefused = 'administrator.bootstrap_refused';
    case CatalogueProposalApproved = 'catalogue.proposal_approved';
    case RecipeNutritionOverrideApplied = 'recipe.nutrition_override_applied';
    case PlanSnapshotRecorded = 'plan.snapshot_recorded';
    case AccountAnonymizationCompleted = 'account.anonymization_completed';

    public function purpose(): AuditPurpose
    {
        return match ($this) {
            self::AdministratorBootstrapCompleted,
            self::AdministratorBootstrapRefused => AuditPurpose::PrivilegedAccessAccountability,
            self::CatalogueProposalApproved => AuditPurpose::CatalogueProvenance,
            self::RecipeNutritionOverrideApplied,
            self::PlanSnapshotRecorded => AuditPurpose::ProductHistory,
            self::AccountAnonymizationCompleted => AuditPurpose::AccountErasureEvidence,
        };
    }

    public function retentionClass(): AuditRetentionClass
    {
        return match ($this) {
            self::AdministratorBootstrapCompleted,
            self::AdministratorBootstrapRefused => AuditRetentionClass::PrivilegedIdentityTwelveMonths,
            self::CatalogueProposalApproved => AuditRetentionClass::ProvenanceActiveVersionPlusTwelveMonths,
            self::RecipeNutritionOverrideApplied,
            self::PlanSnapshotRecorded => AuditRetentionClass::PrivateContentUntilFinalPurge,
            self::AccountAnonymizationCompleted => AuditRetentionClass::PurgeReceiptTwelveMonths,
        };
    }

    /**
     * @return list<AuditActorType>
     */
    public function allowedActorTypes(): array
    {
        return match ($this) {
            self::AdministratorBootstrapCompleted,
            self::AdministratorBootstrapRefused => [
                AuditActorType::ExternalOperator,
                AuditActorType::Deployment,
            ],
            self::CatalogueProposalApproved => [AuditActorType::Administrator],
            self::RecipeNutritionOverrideApplied => [
                AuditActorType::AuthenticatedUser,
                AuditActorType::Administrator,
            ],
            self::PlanSnapshotRecorded,
            self::AccountAnonymizationCompleted => [AuditActorType::System],
        };
    }

    /**
     * @return list<AuditSubjectType>
     */
    public function allowedSubjectTypes(): array
    {
        return match ($this) {
            self::AdministratorBootstrapCompleted,
            self::AdministratorBootstrapRefused,
            self::AccountAnonymizationCompleted => [AuditSubjectType::UserAccount],
            self::CatalogueProposalApproved => [AuditSubjectType::CatalogueProposal],
            self::RecipeNutritionOverrideApplied => [
                AuditSubjectType::Recipe,
                AuditSubjectType::NutritionOverride,
            ],
            self::PlanSnapshotRecorded => [AuditSubjectType::PlanSnapshot],
        };
    }
}
