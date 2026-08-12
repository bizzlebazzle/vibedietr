<?php

namespace App\Administrator;

use App\Models\AdministratorLifecycleState;
use App\Models\AdministratorPromotionRequest;
use App\Models\User;
use App\Security\Notifications\SecurityEventType;
use App\Security\Notifications\SecurityNotificationIntentService;
use App\Security\SecondFactor\PrivilegedWorkflowGuard;
use App\Security\SecurityAuditService;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class AdministratorRevocation
{
    public function __construct(
        private readonly PrivilegedWorkflowGuard $guard,
        private readonly AdministratorLifecycleAudit $audit,
        private readonly AdministratorPrivilegeMutation $privileges,
        private readonly AdministratorSessionInvalidator $sessions,
        private readonly SecurityNotificationIntentService $notifications,
        private readonly SecurityAuditService $securityAudit,
    ) {}

    public function revoke(User $actor, User $target, Session $session): User
    {
        if ($actor->is($target)) {
            throw new RuntimeException('Administrators cannot revoke themselves.');
        }
        if (! $this->guard->allows($actor, 'administrator.revoke', $session)) {
            throw new RuntimeException('Recent primary authentication and a fresh revocation TOTP are required.');
        }
        $correlationId = strtolower((string) Str::ulid());

        $revoked = DB::transaction(function () use ($actor, $target, $correlationId): User {
            AdministratorLifecycleState::query()->lockForUpdate()->findOrFail(1);
            User::query()->where('is_administrator', true)->orderBy('id')->lockForUpdate()->get();
            $lockedActor = User::query()->lockForUpdate()->findOrFail($actor->getKey());
            $lockedTarget = User::query()->lockForUpdate()->findOrFail($target->getKey());
            if (! $lockedActor->isAdministrator() || ! $lockedTarget->isAdministrator()) {
                throw new RuntimeException('Both accounts must be active administrators.');
            }
            if (User::query()->where('is_administrator', true)->count() <= 1) {
                throw new RuntimeException('The final administrator cannot be revoked.');
            }

            $this->audit->user($lockedActor, $lockedTarget, 'privilege_revoked', 'completed', 'administrator', 'ordinary', $correlationId);
            AdministratorPromotionRequest::query()->where('initiated_by_user_id', $lockedTarget->getKey())->where('status', 'pending')
                ->update(['status' => 'cancelled', 'cancelled_at' => Date::now()]);
            $this->privileges->set($lockedTarget, false);
            $this->sessions->invalidate($lockedTarget);

            return $lockedTarget->fresh();
        }, 3);

        try {
            $this->notifications->create(SecurityEventType::PrivilegeRevoked, $revoked, $correlationId);
        } catch (\Throwable) {
            try {
                $this->securityAudit->notification($revoked, SecurityEventType::PrivilegeRevoked->value, 'failed', 'affected_account', 'intent_failed', $correlationId);
            } catch (\Throwable) {
                // Revocation remains complete even if failure evidence is unavailable.
            }
        }

        return $revoked;
    }
}
