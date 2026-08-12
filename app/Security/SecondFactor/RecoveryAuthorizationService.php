<?php

namespace App\Security\SecondFactor;

use App\Models\SecondFactorRecoveryAuthorization;
use App\Models\User;
use App\Security\Notifications\SecurityEventType;
use App\Security\Notifications\SecurityNotificationIntentService;
use App\Security\SecurityAuditService;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use SensitiveParameter;

final class RecoveryAuthorizationService
{
    public function __construct(
        private readonly RecentAuthentication $authentication,
        private readonly SecondFactorVerifier $verifier,
        private readonly SecurityAuditService $audit,
        private readonly SecurityNotificationIntentService $notifications,
    ) {}

    public function initiateAssisted(
        User $actor,
        User $target,
        #[SensitiveParameter] string $password,
        #[SensitiveParameter] string $totpCode,
        string $sourceIp,
        Session $session,
        string $correlationId,
    ): SecondFactorRecoveryAuthorization {
        if ($actor->is($target) || ! $actor->isAdministrator() || ! $target->isAdministrator() || $target->email_verified_at === null || ! $target->hasConfirmedSecondFactor()) {
            throw new RuntimeException('Assisted recovery is not available for these accounts.');
        }
        if (! $this->authentication->confirmPrimary($actor, $password, $session)) {
            throw new RuntimeException('Immediate password confirmation failed.');
        }
        if (! $this->verifier->verify($actor, $totpCode, 'assisted_recovery_initiate', $sourceIp)->succeeded()) {
            throw new RuntimeException('Fresh second-factor verification failed.');
        }

        $authorization = $this->create($target, 'assisted_administrator', $correlationId, $actor);
        $this->audit->factor($actor, $target, 'assisted_recovery_initiated', 'completed', 'assisted_recovery', $correlationId);
        $this->notifications->create(SecurityEventType::AssistedRecoveryInitiated, $target, $correlationId);

        return $authorization;
    }

    public function issueCli(User $target, string $operatorReference, string $correlationId): CliRecoveryAuthorization
    {
        if (! $target->isAdministrator() || $target->email_verified_at === null || ! $target->hasConfirmedSecondFactor()) {
            throw new RuntimeException('CLI recovery is not available for this account.');
        }

        $value = implode('-', str_split(strtoupper(bin2hex(random_bytes(16))), 8));
        $authorization = $this->create($target, 'deployment_cli', $correlationId, null, Hash::make($value), $operatorReference);
        $this->audit->factorSystem($target, 'cli_recovery_initiated', 'completed', 'cli_recovery', $correlationId);
        $this->notifications->create(SecurityEventType::CliRecoveryInitiated, $target, $correlationId);

        return new CliRecoveryAuthorization((string) $authorization->getKey(), $value);
    }

    public function establishAssistedSession(User $target, string $authorizationId, Session $session): bool
    {
        return $this->establish($target, $authorizationId, null, $session);
    }

    public function establishCliSession(User $target, string $authorizationId, #[SensitiveParameter] string $value, Session $session): bool
    {
        return $this->establish($target, $authorizationId, $value, $session);
    }

    public function cancel(SecondFactorRecoveryAuthorization $authorization): void
    {
        if ($authorization->consumed_at === null && $authorization->cancelled_at === null) {
            $authorization->forceFill(['cancelled_at' => Date::now()])->save();
            $target = $authorization->target()->firstOrFail();
            $event = $authorization->method === 'deployment_cli'
                ? SecurityEventType::CliRecoveryCancelled
                : SecurityEventType::AssistedRecoveryCancelled;
            $this->audit->factorSystem($target, $event->value, 'cancelled', 'factor_recovery', $authorization->correlation_id);
            $this->notifications->create($event, $target, $authorization->correlation_id);
        }
    }

    private function establish(User $target, string $authorizationId, ?string $value, Session $session): bool
    {
        $authorization = SecondFactorRecoveryAuthorization::query()
            ->whereKey($authorizationId)
            ->where('target_user_id', $target->getKey())
            ->whereNull('consumed_at')
            ->whereNull('cancelled_at')
            ->where('expires_at', '>=', Date::now())
            ->first();

        if ($authorization === null || ($authorization->method === 'deployment_cli' && ($value === null || ! Hash::check($value, $authorization->authorization_hash)))) {
            return false;
        }
        if ($authorization->method === 'assisted_administrator' && $value !== null) {
            return false;
        }

        $session->put('auth.second_factor_recovery', [
            'user_id' => (int) $target->getKey(),
            'authorization_id' => (string) $authorization->getKey(),
            'expires_at' => min(
                Date::parse($authorization->expires_at)->getTimestamp(),
                Date::now()->addSeconds((int) config('administrator-security.totp.recovery_session_ttl_seconds'))->getTimestamp(),
            ),
        ]);

        return true;
    }

    private function create(
        User $target,
        string $method,
        string $correlationId,
        ?User $initiator = null,
        ?string $hash = null,
        ?string $operatorReference = null,
    ): SecondFactorRecoveryAuthorization {
        $authorization = new SecondFactorRecoveryAuthorization;
        $authorization->forceFill([
            'id' => $authorization->newUniqueId(),
            'target_user_id' => $target->getKey(),
            'initiated_by_user_id' => $initiator?->getKey(),
            'method' => $method,
            'authorization_hash' => $hash,
            'operator_reference' => $operatorReference,
            'correlation_id' => $correlationId,
            'expires_at' => Date::now()->addSeconds($method === 'deployment_cli' ? 600 : (int) config('administrator-security.totp.assisted_recovery_ttl_seconds')),
        ]);
        $authorization->save();

        return $authorization;
    }
}
