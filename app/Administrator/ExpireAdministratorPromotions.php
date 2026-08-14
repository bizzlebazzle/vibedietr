<?php

namespace App\Administrator;

use App\Models\AdministratorPromotionRequest;
use App\Models\User;
use App\Security\Notifications\SecurityEventType;
use App\Security\Notifications\SecurityNotificationIntentService;
use App\Security\SecurityAuditService;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

final class ExpireAdministratorPromotions
{
    public function __construct(
        private readonly AdministratorLifecycleAudit $audit,
        private readonly SecurityNotificationIntentService $notifications,
        private readonly SecurityAuditService $securityAudit,
    ) {}

    public function execute(): int
    {
        $expired = 0;
        $ids = AdministratorPromotionRequest::query()->where('status', 'pending')->where('expires_at', '<=', Date::now())->pluck('id');

        foreach ($ids as $id) {
            $promotion = DB::transaction(function () use ($id): ?AdministratorPromotionRequest {
                $locked = AdministratorPromotionRequest::query()->lockForUpdate()->find($id);
                if (! $locked instanceof AdministratorPromotionRequest || ! $locked->isPending() || $locked->expires_at->isFuture()) {
                    return null;
                }
                $target = User::query()->findOrFail($locked->target_user_id);
                $locked->update(['status' => 'expired', 'expired_at' => Date::now()]);
                $this->audit->system($target, 'promotion_expired', 'completed', 'ordinary', 'ordinary', $locked->correlation_id);

                return $locked->fresh();
            }, 3);

            if ($promotion instanceof AdministratorPromotionRequest) {
                $expired++;
                try {
                    $this->notifications->create(SecurityEventType::PromotionExpired, User::query()->findOrFail($promotion->target_user_id), $promotion->correlation_id);
                } catch (\Throwable) {
                    try {
                        $this->securityAudit->notification(User::query()->findOrFail($promotion->target_user_id), SecurityEventType::PromotionExpired->value, 'failed', 'affected_account', 'intent_failed', $promotion->correlation_id);
                    } catch (\Throwable) {
                        // Expiry remains complete even if failure evidence is unavailable.
                    }
                }
            }
        }

        return $expired;
    }
}
