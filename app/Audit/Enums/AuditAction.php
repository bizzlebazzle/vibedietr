<?php

namespace App\Audit\Enums;

enum AuditAction: string
{
    case AdministratorBootstrapCompleted = 'administrator.bootstrap_completed';
    case AdministratorBootstrapRefused = 'administrator.bootstrap_refused';
    case AdministratorLifecycleEvent = 'administrator.lifecycle_event';
    case CatalogueProposalApproved = 'catalogue.proposal_approved';
    case RecipeFinalized = 'recipe.finalized';
    case RecipeVisibilityChanged = 'recipe.visibility_changed';
    case RecipeNutritionOverrideApplied = 'recipe.nutrition_override_applied';
    case PlanSnapshotRecorded = 'plan.snapshot_recorded';
    case AccountAnonymizationCompleted = 'account.anonymization_completed';
    case SecuritySecondFactorEvent = 'security.second_factor_event';
    case SecurityNotificationEvent = 'security.notification_event';

    public function purpose(): AuditPurpose
    {
        return match ($this) {
            self::AdministratorBootstrapCompleted,
            self::AdministratorBootstrapRefused,
            self::AdministratorLifecycleEvent => AuditPurpose::PrivilegedAccessAccountability,
            self::CatalogueProposalApproved => AuditPurpose::CatalogueProvenance,
            self::RecipeFinalized,
            self::RecipeVisibilityChanged,
            self::RecipeNutritionOverrideApplied,
            self::PlanSnapshotRecorded => AuditPurpose::ProductHistory,
            self::AccountAnonymizationCompleted => AuditPurpose::AccountErasureEvidence,
            self::SecuritySecondFactorEvent,
            self::SecurityNotificationEvent => AuditPurpose::AccountSecurity,
        };
    }

    public function retentionClass(): AuditRetentionClass
    {
        return match ($this) {
            self::AdministratorBootstrapCompleted,
            self::AdministratorBootstrapRefused,
            self::AdministratorLifecycleEvent => AuditRetentionClass::PrivilegedIdentityTwelveMonths,
            self::CatalogueProposalApproved => AuditRetentionClass::ProvenanceActiveVersionPlusTwelveMonths,
            self::RecipeFinalized,
            self::RecipeVisibilityChanged,
            self::RecipeNutritionOverrideApplied,
            self::PlanSnapshotRecorded => AuditRetentionClass::PrivateContentUntilFinalPurge,
            self::AccountAnonymizationCompleted => AuditRetentionClass::PurgeReceiptTwelveMonths,
            self::SecuritySecondFactorEvent,
            self::SecurityNotificationEvent => AuditRetentionClass::SecurityEventSixMonths,
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
            self::AdministratorLifecycleEvent => [
                AuditActorType::Administrator,
                AuditActorType::AuthenticatedUser,
                AuditActorType::ExternalOperator,
                AuditActorType::Deployment,
                AuditActorType::System,
            ],
            self::CatalogueProposalApproved => [AuditActorType::Administrator],
            self::RecipeFinalized,
            self::RecipeVisibilityChanged => [AuditActorType::AuthenticatedUser],
            self::RecipeNutritionOverrideApplied => [
                AuditActorType::AuthenticatedUser,
                AuditActorType::Administrator,
            ],
            self::PlanSnapshotRecorded,
            self::AccountAnonymizationCompleted => [AuditActorType::System],
            self::SecuritySecondFactorEvent,
            self::SecurityNotificationEvent => [
                AuditActorType::AuthenticatedUser,
                AuditActorType::Administrator,
                AuditActorType::System,
            ],
        };
    }

    /**
     * @return list<AuditSubjectType>
     */
    public function allowedSubjectTypes(): array
    {
        return match ($this) {
            self::AdministratorBootstrapCompleted,
            self::AccountAnonymizationCompleted => [AuditSubjectType::UserAccount],
            self::AdministratorBootstrapRefused,
            self::AdministratorLifecycleEvent => [AuditSubjectType::UserAccount, AuditSubjectType::SystemOperation],
            self::CatalogueProposalApproved => [AuditSubjectType::CatalogueProposal],
            self::RecipeFinalized,
            self::RecipeVisibilityChanged => [AuditSubjectType::Recipe],
            self::RecipeNutritionOverrideApplied => [
                AuditSubjectType::Recipe,
                AuditSubjectType::NutritionOverride,
            ],
            self::PlanSnapshotRecorded => [AuditSubjectType::PlanSnapshot],
            self::SecuritySecondFactorEvent,
            self::SecurityNotificationEvent => [AuditSubjectType::UserAccount],
        };
    }
}
