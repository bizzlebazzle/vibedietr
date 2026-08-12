<?php

namespace App\Administrator;

use App\Models\AdministratorLifecycleState;
use App\Models\SecondFactorRecoveryAuthorization;
use App\Models\User;
use App\Security\Notifications\ProductionSecurityReadiness;
use App\Security\Notifications\SecurityEventType;
use App\Security\Notifications\SecurityNotificationIntentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class AdministratorBreakGlassReplacement
{
    public function __construct(
        private readonly AdministratorLifecycleAudit $audit,
        private readonly AdministratorPrivilegeMutation $privileges,
        private readonly AdministratorSessionInvalidator $sessions,
        private readonly SecurityNotificationIntentService $notifications,
        private readonly ProductionSecurityReadiness $readiness,
    ) {}

    /** @return array{replacement: User, compromised: ?User} */
    public function targets(): array
    {
        $this->assertConfiguration();
        $replacement = $this->userByConfiguredEmail('replacement_email');
        $compromisedEmail = config('administrator-security.lifecycle.break_glass.compromised_email');
        $compromised = is_string($compromisedEmail) && $compromisedEmail !== ''
            ? $this->userByConfiguredEmail('compromised_email')
            : null;

        return ['replacement' => $replacement, 'compromised' => $compromised];
    }

    public function execute(): User
    {
        try {
            return $this->perform();
        } catch (Throwable $exception) {
            $this->recordRefusal();

            throw $exception;
        }
    }

    public function recordOperatorDeclined(): void
    {
        $this->recordRefusal('operator_declined');
    }

    private function recordRefusal(string $reason = 'precondition_failed'): void
    {
        $email = config('administrator-security.lifecycle.break_glass.replacement_email');
        $target = is_string($email) ? User::query()->whereRaw('LOWER(email) = ?', [Str::lower($email)])->first() : null;
        $operator = config('administrator-security.lifecycle.break_glass.operator_reference');
        $correlationId = strtolower((string) Str::ulid());
        $previous = $target instanceof User && $target->isAdministrator() ? 'administrator' : 'ordinary';

        try {
            if ($target instanceof User && is_string($operator) && $operator !== '') {
                $this->audit->external($operator, $target, 'break_glass_refused', 'refused', $previous, $previous, $correlationId, $reason);
            } elseif (is_string($operator) && $operator !== '') {
                $this->audit->externalSystem($operator, 'break_glass_refused', 'refused', 'ordinary', 'ordinary', $correlationId, 'configuration_mismatch');
            } else {
                $this->audit->systemOperation('break_glass_refused', 'refused', 'ordinary', 'ordinary', $correlationId, 'configuration_mismatch');
            }
        } catch (Throwable) {
            // The command remains failed when refusal evidence is unavailable.
        }
    }

    private function perform(): User
    {
        $this->readiness->assertReady();
        ['replacement' => $replacement, 'compromised' => $compromised] = $this->targets();
        $operator = (string) config('administrator-security.lifecycle.break_glass.operator_reference');
        $correlationId = strtolower((string) Str::ulid());

        return DB::transaction(function () use ($replacement, $compromised, $operator, $correlationId): User {
            $state = AdministratorLifecycleState::query()->lockForUpdate()->findOrFail(1);
            if ($state->bootstrap_completed_at === null) {
                throw new RuntimeException('Break-glass replacement requires a completed initial bootstrap.');
            }
            $administrators = User::query()->where('is_administrator', true)->orderBy('id')->lockForUpdate()->get();
            $lockedReplacement = User::query()->lockForUpdate()->findOrFail($replacement->getKey());
            $lockedCompromised = $compromised === null ? null : User::query()->lockForUpdate()->findOrFail($compromised->getKey());

            if ($lockedReplacement->isAdministrator() || $lockedReplacement->email_verified_at === null || ! $lockedReplacement->hasConfirmedSecondFactor()) {
                throw new RuntimeException('The configured break-glass replacement is not eligible.');
            }
            if ($lockedCompromised !== null && ! $lockedCompromised->isAdministrator()) {
                throw new RuntimeException('The configured compromised account is not an active administrator.');
            }
            $otherUsable = $administrators->contains(function (User $administrator) use ($lockedCompromised): bool {
                if ($lockedCompromised !== null && $administrator->is($lockedCompromised)) {
                    return false;
                }

                return $administrator->email_verified_at !== null
                    && $administrator->hasConfirmedSecondFactor()
                    && ! SecondFactorRecoveryAuthorization::query()->where('target_user_id', $administrator->getKey())->whereNull('consumed_at')->whereNull('cancelled_at')->where('expires_at', '>=', now())->exists();
            });
            if ($otherUsable) {
                throw new RuntimeException('Break-glass replacement is unavailable while another administrator is usable.');
            }

            $this->audit->external($operator, $lockedReplacement, 'break_glass_replacement', 'completed', 'ordinary', 'administrator', $correlationId);
            $this->privileges->set($lockedReplacement, true);
            if ($lockedCompromised !== null) {
                $this->audit->external($operator, $lockedCompromised, 'break_glass_revocation', 'completed', 'administrator', 'ordinary', $correlationId);
                $this->privileges->set($lockedCompromised, false);
                $this->sessions->invalidate($lockedCompromised);
                $this->notifications->create(SecurityEventType::PrivilegeRevoked, $lockedCompromised, $correlationId);
            }
            $this->notifications->create(SecurityEventType::BreakGlassRecoveryCompleted, $lockedReplacement, $correlationId);

            return $lockedReplacement->fresh();
        }, 3);
    }

    private function assertConfiguration(): void
    {
        $configuration = config('administrator-security.lifecycle.break_glass');
        if (! is_array($configuration)
            || $configuration['enabled'] !== true
            || ! is_string($configuration['expected_environment'])
            || $configuration['expected_environment'] !== app()->environment()
            || ! is_string($configuration['replacement_email'])
            || filter_var($configuration['replacement_email'], FILTER_VALIDATE_EMAIL) === false
            || ! is_string($configuration['operator_reference'])
            || $configuration['operator_reference'] === '') {
            throw new RuntimeException('Break-glass configuration is missing or does not match this environment.');
        }
    }

    private function userByConfiguredEmail(string $key): User
    {
        $email = Str::lower((string) config("administrator-security.lifecycle.break_glass.$key"));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if (! $user instanceof User) {
            throw new RuntimeException('A configured break-glass account does not exist.');
        }

        return $user;
    }
}
