<?php

namespace App\Security\SecondFactor;

use App\Models\SecondFactor;
use App\Models\SecondFactorRecoveryCode;
use App\Models\User;
use App\Security\Notifications\SecurityEventType;
use App\Security\Notifications\SecurityNotificationIntentService;
use App\Security\SecurityAuditService;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;

final class RecoveryCodeManager
{
    public function __construct(
        private readonly RecentAuthentication $authentication,
        private readonly SecondFactorVerifier $verifier,
        private readonly SecurityAuditService $audit,
        private readonly SecurityNotificationIntentService $notifications,
    ) {}

    public function regenerate(
        User $user,
        #[SensitiveParameter] string $password,
        #[SensitiveParameter] string $totpCode,
        string $sourceIp,
        Session $session,
    ): RecoveryCodeSet {
        if (! $this->authentication->confirmPrimary($user, $password, $session)) {
            throw new RuntimeException('Immediate password confirmation failed.');
        }

        if (! $this->verifier->verify($user, $totpCode, 'recovery_codes_regenerate', $sourceIp)->succeeded()) {
            throw new RuntimeException('Fresh second-factor verification failed.');
        }

        $codes = DB::transaction(function () use ($user): RecoveryCodeSet {
            $factor = SecondFactor::query()->where('user_id', $user->getKey())->lockForUpdate()->firstOrFail();
            $factor->recoveryCodes()->delete();
            $codes = [];

            for ($index = 0; $index < 10; $index++) {
                $raw = strtoupper(bin2hex(random_bytes(16)));
                $value = implode('-', str_split($raw, 8));
                $record = new SecondFactorRecoveryCode;
                $record->forceFill(['id' => $record->newUniqueId(), 'factor_id' => $factor->getKey(), 'code_hash' => Hash::make($value)]);
                $record->save();
                $codes[] = $value;
            }

            return new RecoveryCodeSet($codes);
        });
        $correlationId = strtolower((string) Str::ulid());
        $this->audit->factor($user, $user, 'recovery_codes_regenerated', 'completed', 'factor_management', $correlationId);
        $this->notifications->create(SecurityEventType::RecoveryCodesRegenerated, $user, $correlationId);

        return $codes;
    }
}
