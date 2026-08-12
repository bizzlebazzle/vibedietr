<?php

namespace App\Administrator;

use App\Models\AdministratorLifecycleState;
use App\Models\AdministratorPromotionRequest;
use App\Models\User;
use App\Security\Notifications\ProductionSecurityReadiness;
use App\Security\Notifications\SecurityEventType;
use App\Security\Notifications\SecurityNotificationIntentService;
use App\Security\SecondFactor\PrivilegedWorkflowGuard;
use App\Security\SecurityAuditService;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class AdministratorPromotionLifecycle
{
    public function __construct(
        private readonly PrivilegedWorkflowGuard $administratorGuard,
        private readonly StrongAuthenticationGuard $targetGuard,
        private readonly AdministratorLifecycleAudit $audit,
        private readonly AdministratorPrivilegeMutation $privileges,
        private readonly SecurityNotificationIntentService $notifications,
        private readonly SecurityAuditService $securityAudit,
        private readonly ProductionSecurityReadiness $readiness,
    ) {}

    public function initiate(User $administrator, User $target, Session $session): AdministratorPromotionRequest
    {
        if (! $this->administratorGuard->allows($administrator, 'administrator.promotion.initiate', $session)) {
            throw new RuntimeException('Recent primary authentication and a fresh promotion TOTP are required.');
        }
        $this->readiness->assertReady();
        $correlationId = strtolower((string) Str::ulid());

        return DB::transaction(function () use ($administrator, $target, $correlationId): AdministratorPromotionRequest {
            AdministratorLifecycleState::query()->lockForUpdate()->findOrFail(1);
            $actor = User::query()->lockForUpdate()->findOrFail($administrator->getKey());
            $subject = User::query()->lockForUpdate()->findOrFail($target->getKey());
            if (! $actor->isAdministrator()) {
                throw new RuntimeException('Only an active administrator may initiate promotion.');
            }
            $this->assertEligibleTarget($subject);
            if (AdministratorPromotionRequest::query()->where('target_user_id', $subject->getKey())->where('status', 'pending')->lockForUpdate()->exists()) {
                throw new RuntimeException('The target already has a pending promotion.');
            }

            $request = AdministratorPromotionRequest::query()->create([
                'target_user_id' => $subject->getKey(),
                'initiated_by_user_id' => $actor->getKey(),
                'status' => 'pending',
                'correlation_id' => $correlationId,
                'expires_at' => Date::now()->addSeconds((int) config('administrator-security.lifecycle.promotion_ttl_seconds')),
            ]);
            $this->audit->user($actor, $subject, 'promotion_initiated', 'completed', 'ordinary', 'ordinary', $correlationId);
            $this->notifications->create(SecurityEventType::PromotionInitiated, $subject, $correlationId);

            return $request;
        }, 3);
    }

    public function accept(User $target, AdministratorPromotionRequest $promotion, Session $session): AdministratorPromotionRequest
    {
        if (! $this->targetGuard->consume($target, 'administrator.promotion.accept', $session)) {
            throw new RuntimeException('Recent primary authentication and the target account fresh TOTP are required.');
        }
        $this->readiness->assertReady();

        return DB::transaction(function () use ($target, $promotion): AdministratorPromotionRequest {
            AdministratorLifecycleState::query()->lockForUpdate()->findOrFail(1);
            $locked = AdministratorPromotionRequest::query()->lockForUpdate()->findOrFail($promotion->getKey());
            $subject = User::query()->lockForUpdate()->findOrFail($target->getKey());
            if ((int) $locked->target_user_id !== (int) $subject->getKey()) {
                throw new RuntimeException('This promotion belongs to a different account.');
            }
            if ($locked->status === 'accepted' && $subject->isAdministrator()) {
                return $locked;
            }
            if (! $locked->isPending() || $locked->expires_at->lessThanOrEqualTo(Date::now())) {
                throw new RuntimeException('The promotion is no longer available.');
            }
            $this->assertEligibleTarget($subject);
            $this->audit->user($subject, $subject, 'promotion_accepted', 'completed', 'ordinary', 'administrator', $locked->correlation_id);
            $this->privileges->set($subject, true);
            $locked->update(['status' => 'accepted', 'accepted_at' => Date::now()]);
            $this->notifications->create(SecurityEventType::PromotionAccepted, $subject, $locked->correlation_id);

            return $locked->fresh();
        }, 3);
    }

    public function decline(User $target, AdministratorPromotionRequest $promotion, Session $session): AdministratorPromotionRequest
    {
        if (! $this->targetGuard->consume($target, 'administrator.promotion.decline', $session)) {
            throw new RuntimeException('Recent primary authentication and the target account fresh TOTP are required.');
        }

        $result = DB::transaction(function () use ($target, $promotion): AdministratorPromotionRequest {
            $locked = AdministratorPromotionRequest::query()->lockForUpdate()->findOrFail($promotion->getKey());
            if ((int) $locked->target_user_id !== (int) $target->getKey()) {
                throw new RuntimeException('This promotion belongs to a different account.');
            }
            if ($locked->status === 'declined') {
                return $locked;
            }
            if (! $locked->isPending()) {
                throw new RuntimeException('The promotion is no longer pending.');
            }
            $locked->update(['status' => 'declined', 'declined_at' => Date::now()]);
            $this->audit->user($target, $target, 'promotion_declined', 'completed', 'ordinary', 'ordinary', $locked->correlation_id);

            return $locked->fresh();
        }, 3);
        $this->notifyBestEffort(SecurityEventType::PromotionDeclined, $target, $result->correlation_id);

        return $result;
    }

    public function cancel(User $administrator, AdministratorPromotionRequest $promotion, Session $session): AdministratorPromotionRequest
    {
        if (! $this->administratorGuard->allows($administrator, 'administrator.promotion.cancel', $session)) {
            throw new RuntimeException('Recent primary authentication and a fresh cancellation TOTP are required.');
        }

        $result = DB::transaction(function () use ($administrator, $promotion): AdministratorPromotionRequest {
            $actor = User::query()->lockForUpdate()->findOrFail($administrator->getKey());
            $locked = AdministratorPromotionRequest::query()->lockForUpdate()->findOrFail($promotion->getKey());
            if (! $actor->isAdministrator()) {
                throw new RuntimeException('Only an active administrator may cancel promotion.');
            }
            if ($locked->status === 'cancelled') {
                return $locked;
            }
            if (! $locked->isPending()) {
                throw new RuntimeException('The promotion is no longer pending.');
            }
            $subject = User::query()->findOrFail($locked->target_user_id);
            $locked->update(['status' => 'cancelled', 'cancelled_at' => Date::now()]);
            $this->audit->user($actor, $subject, 'promotion_cancelled', 'completed', 'ordinary', 'ordinary', $locked->correlation_id);

            return $locked->fresh();
        }, 3);
        $this->notifyBestEffort(SecurityEventType::PromotionCancelled, User::query()->findOrFail($result->target_user_id), $result->correlation_id);

        return $result;
    }

    private function assertEligibleTarget(User $target): void
    {
        if ($target->isAdministrator() || $target->email_verified_at === null || ! $target->hasConfirmedSecondFactor()) {
            throw new RuntimeException('The promotion target is not eligible.');
        }
    }

    private function notifyBestEffort(SecurityEventType $event, User $target, string $correlationId): void
    {
        try {
            $this->notifications->create($event, $target, $correlationId);
        } catch (\Throwable) {
            try {
                $this->securityAudit->notification($target, $event->value, 'failed', 'affected_account', 'intent_failed', $correlationId);
            } catch (\Throwable) {
                // The transition remains complete even if failure evidence is unavailable.
            }
        }
    }
}
