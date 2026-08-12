<?php

namespace App\Security\Notifications;

enum SecurityEventType: string
{
    case BootstrapCompleted = 'administrator.bootstrap_completed';
    case PromotionInitiated = 'administrator.promotion_initiated';
    case PromotionAccepted = 'administrator.promotion_accepted';
    case PromotionDeclined = 'administrator.promotion_declined';
    case PromotionCancelled = 'administrator.promotion_cancelled';
    case PromotionExpired = 'administrator.promotion_expired';
    case PrivilegeRevoked = 'administrator.privilege_revoked';
    case BreakGlassRecoveryCompleted = 'administrator.break_glass_recovery_completed';
    case FactorEnrollmentCompleted = 'second_factor.enrollment_completed';
    case FactorReplaced = 'second_factor.replaced';
    case RecoveryCodesRegenerated = 'second_factor.recovery_codes_regenerated';
    case RecoveryCodeUsed = 'second_factor.recovery_code_used';
    case VerificationLocked = 'second_factor.verification_locked';
    case AssistedRecoveryInitiated = 'second_factor.assisted_recovery_initiated';
    case AssistedRecoveryExpired = 'second_factor.assisted_recovery_expired';
    case AssistedRecoveryCancelled = 'second_factor.assisted_recovery_cancelled';
    case AssistedRecoveryRefused = 'second_factor.assisted_recovery_refused';
    case AssistedRecoveryCompleted = 'second_factor.assisted_recovery_completed';
    case CliRecoveryInitiated = 'second_factor.cli_recovery_initiated';
    case CliRecoveryExpired = 'second_factor.cli_recovery_expired';
    case CliRecoveryCancelled = 'second_factor.cli_recovery_cancelled';
    case CliRecoveryRefused = 'second_factor.cli_recovery_refused';
    case CliRecoveryCompleted = 'second_factor.cli_recovery_completed';
    case FinalFactorRemoved = 'second_factor.final_factor_removed';

    public function label(): string
    {
        return match ($this) {
            self::BootstrapCompleted => 'Administrator access was activated',
            self::PromotionInitiated => 'Administrator promotion was initiated',
            self::PromotionAccepted => 'Administrator promotion was accepted',
            self::PromotionDeclined => 'Administrator promotion was declined',
            self::PromotionCancelled => 'Administrator promotion was cancelled',
            self::PromotionExpired => 'Administrator promotion expired',
            self::PrivilegeRevoked => 'Administrator access was revoked',
            self::BreakGlassRecoveryCompleted => 'Administrator recovery was completed',
            self::FactorEnrollmentCompleted => 'Two-step verification was enabled',
            self::FactorReplaced => 'The authenticator factor was replaced',
            self::RecoveryCodesRegenerated => 'Recovery codes were regenerated',
            self::RecoveryCodeUsed => 'A recovery code was used',
            self::VerificationLocked => 'Two-step verification was temporarily locked',
            self::AssistedRecoveryInitiated => 'Assisted factor recovery was initiated',
            self::AssistedRecoveryExpired => 'Assisted factor recovery expired',
            self::AssistedRecoveryCancelled => 'Assisted factor recovery was cancelled',
            self::AssistedRecoveryRefused => 'Assisted factor recovery was refused',
            self::AssistedRecoveryCompleted => 'Assisted factor recovery was completed',
            self::FinalFactorRemoved => 'The final authenticator factor was removed',
            self::CliRecoveryInitiated => 'Deployment-assisted factor recovery was initiated',
            self::CliRecoveryExpired => 'Deployment-assisted factor recovery expired',
            self::CliRecoveryCancelled => 'Deployment-assisted factor recovery was cancelled',
            self::CliRecoveryRefused => 'Deployment-assisted factor recovery was refused',
            self::CliRecoveryCompleted => 'Deployment-assisted factor recovery was completed',
        };
    }

    public function notifyAllActiveAdministrators(): bool
    {
        return $this !== self::FactorEnrollmentCompleted && $this !== self::FinalFactorRemoved;
    }
}
