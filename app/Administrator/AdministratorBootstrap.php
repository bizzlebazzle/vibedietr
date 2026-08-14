<?php

namespace App\Administrator;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Models\AdministratorLifecycleState;
use App\Models\User;
use App\Security\Notifications\ProductionSecurityReadiness;
use App\Security\Notifications\SecurityEventType;
use App\Security\Notifications\SecurityNotificationIntentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class AdministratorBootstrap
{
    public function __construct(
        private readonly AuditEventRecorder $audit,
        private readonly AdministratorPrivilegeMutation $privileges,
        private readonly AdministratorBootstrapMarker $marker,
        private readonly SecurityNotificationIntentService $notifications,
        private readonly ProductionSecurityReadiness $readiness,
    ) {}

    public function target(): User
    {
        $this->assertConfiguration();
        $email = Str::lower((string) config('administrator-security.lifecycle.bootstrap.target_email'));
        $target = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $target instanceof User) {
            throw new RuntimeException('The configured bootstrap target does not exist.');
        }

        return $target;
    }

    public function recordOperatorDeclined(): void
    {
        $target = $this->target();
        $operator = (string) config('administrator-security.lifecycle.bootstrap.operator_reference');
        $state = AdministratorLifecycleState::query()->findOrFail(1);
        $this->audit->record(
            AuditAction::AdministratorBootstrapRefused,
            AuditActor::externalOperator($operator),
            AuditSubject::user($target),
            $this->bootstrapPayload(
                User::query()->where('is_administrator', true)->count(),
                $state->bootstrap_completed_at !== null,
                true,
                'refused',
                'ordinary',
                'operator_declined',
            ),
            correlationId: strtolower((string) Str::ulid()),
        );
    }

    public function recordConfigurationRefusal(Throwable $exception): void
    {
        $email = config('administrator-security.lifecycle.bootstrap.target_email');
        $target = is_string($email) ? User::query()->whereRaw('LOWER(email) = ?', [Str::lower($email)])->first() : null;
        $operator = config('administrator-security.lifecycle.bootstrap.operator_reference');
        $actor = is_string($operator) && $operator !== ''
            ? AuditActor::externalOperator($operator)
            : AuditActor::deployment('bootstrap-config-validation');
        $expected = config('administrator-security.lifecycle.bootstrap.expected_environment');
        $reason = is_string($expected) && $expected !== app()->environment()
            ? 'environment_mismatch'
            : 'configuration_mismatch';

        try {
            $this->audit->record(
                AuditAction::AdministratorBootstrapRefused,
                $actor,
                $target instanceof User ? AuditSubject::user($target) : AuditSubject::system(),
                $this->bootstrapPayload(
                    User::query()->where('is_administrator', true)->count(),
                    AdministratorLifecycleState::query()->find(1)?->bootstrap_completed_at !== null,
                    $target instanceof User,
                    'refused',
                    'ordinary',
                    $reason,
                ),
                correlationId: strtolower((string) Str::ulid()),
            );
        } catch (Throwable) {
            // The command remains failed when refusal evidence is unavailable.
        }
    }

    public function execute(): User
    {
        $this->readiness->assertReady();
        $target = $this->target();
        $operator = (string) config('administrator-security.lifecycle.bootstrap.operator_reference');
        $correlationId = strtolower((string) Str::ulid());

        try {
            return DB::transaction(function () use ($target, $operator, $correlationId): User {
                $state = AdministratorLifecycleState::query()->lockForUpdate()->findOrFail(1);
                $lockedTarget = User::query()->lockForUpdate()->findOrFail($target->getKey());
                $administratorCount = User::query()->where('is_administrator', true)->lockForUpdate()->count();

                $this->assertEligible($lockedTarget);
                if ($administratorCount !== 0) {
                    throw new RuntimeException('Bootstrap requires zero administrators.');
                }
                if ($state->bootstrap_completed_at !== null) {
                    throw new RuntimeException('Administrator bootstrap has already completed.');
                }

                $this->privileges->set($lockedTarget, true);
                $event = $this->audit->record(
                    AuditAction::AdministratorBootstrapCompleted,
                    AuditActor::externalOperator($operator),
                    AuditSubject::user($lockedTarget),
                    $this->bootstrapPayload($administratorCount, false, true, 'completed', 'administrator'),
                    correlationId: $correlationId,
                );
                $this->notifications->create(SecurityEventType::BootstrapCompleted, $lockedTarget, $correlationId);
                $this->marker->complete($state, $event, $correlationId);

                return $lockedTarget->fresh();
            }, 3);
        } catch (Throwable $exception) {
            $this->recordRefusal($target, $operator, $correlationId, $exception);
            throw $exception;
        }
    }

    private function assertConfiguration(): void
    {
        $configuration = config('administrator-security.lifecycle.bootstrap');
        if (! is_array($configuration)
            || $configuration['enabled'] !== true
            || ! is_string($configuration['expected_environment'])
            || $configuration['expected_environment'] !== app()->environment()
            || ! is_string($configuration['target_email'])
            || filter_var($configuration['target_email'], FILTER_VALIDATE_EMAIL) === false
            || ! is_string($configuration['operator_reference'])
            || $configuration['operator_reference'] === '') {
            throw new RuntimeException('Administrator bootstrap configuration is missing or does not match this environment.');
        }
    }

    private function assertEligible(User $target): void
    {
        if ($target->email_verified_at === null || $target->isAdministrator() || ! $target->hasConfirmedSecondFactor()) {
            throw new RuntimeException('The configured bootstrap target is not eligible.');
        }
    }

    private function recordRefusal(User $target, string $operator, string $correlationId, Throwable $exception): void
    {
        $state = AdministratorLifecycleState::query()->find(1);
        $count = User::query()->where('is_administrator', true)->count();
        $reason = match (true) {
            str_contains($exception->getMessage(), 'already') => 'already_bootstrapped',
            str_contains($exception->getMessage(), 'zero administrators') => 'administrator_exists',
            str_contains($exception->getMessage(), 'eligible') => 'target_ineligible',
            default => 'audit_unavailable',
        };

        try {
            $this->audit->record(
                AuditAction::AdministratorBootstrapRefused,
                AuditActor::externalOperator($operator),
                AuditSubject::user($target),
                $this->bootstrapPayload($count, $state?->bootstrap_completed_at !== null, true, 'refused', 'ordinary', $reason),
                correlationId: $correlationId,
            );
        } catch (Throwable) {
            // The command still fails closed when refusal evidence is itself unavailable.
        }
    }

    /** @return array<string, mixed> */
    private function bootstrapPayload(int $count, bool $marker, bool $match, string $outcome, string $resulting, ?string $reason = null): array
    {
        $environment = app()->environment();
        if (! in_array($environment, ['production', 'staging', 'local', 'testing'], true)) {
            $environment = 'local';
        }

        return array_filter([
            'administrator_count_before' => $count,
            'application_instance_reference' => (string) config('administrator-security.notifications.application_instance'),
            'bootstrap_marker_previously_set' => $marker,
            'configured_target_match' => $match,
            'environment_category' => $environment,
            'operation_version' => (string) config('administrator-security.lifecycle.bootstrap.operation_version'),
            'outcome' => $outcome,
            'previous_privilege_state' => 'ordinary',
            'resulting_privilege_state' => $resulting,
            'refusal_reason_code' => $reason,
        ], fn ($value) => $value !== null);
    }
}
