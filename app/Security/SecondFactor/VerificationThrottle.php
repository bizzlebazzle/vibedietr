<?php

namespace App\Security\SecondFactor;

use App\Models\SecondFactor;
use App\Models\SecondFactorAccountState;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class VerificationThrottle
{
    public function check(User $user, ?SecondFactor $factor, string $operation, string $sourceIp): ?SecondFactorStatus
    {
        $now = Date::now();
        $state = SecondFactorAccountState::query()->find($user->getKey());

        if ($state !== null && $state->locked_until !== null && Date::parse($state->locked_until)->isFuture()) {
            return SecondFactorStatus::Locked;
        }

        if ($state !== null && $state->next_attempt_at !== null && Date::parse($state->next_attempt_at)->isFuture()) {
            return SecondFactorStatus::Throttled;
        }

        $since = $now->copy()->subSeconds((int) config('administrator-security.verification.rolling_window_seconds'));
        $factorId = $factor?->getKey();
        $scopeFailures = DB::table('second_factor_verification_failures')
            ->where('user_id', $user->getKey())
            ->where('factor_id', $factorId)
            ->where('operation', $operation)
            ->where('occurred_at', '>=', $since)
            ->count();
        $sourceFailures = DB::table('second_factor_verification_failures')
            ->where('source_fingerprint', $this->sourceFingerprint($sourceIp))
            ->where('occurred_at', '>=', $since)
            ->count();

        return max($scopeFailures, $sourceFailures) >= (int) config('administrator-security.verification.maximum_failures')
            ? SecondFactorStatus::Throttled
            : null;
    }

    public function failed(User $user, ?SecondFactor $factor, string $operation, string $sourceIp): SecondFactorStatus
    {
        return DB::transaction(function () use ($user, $factor, $operation, $sourceIp): SecondFactorStatus {
            $now = Date::now();
            $state = SecondFactorAccountState::query()->lockForUpdate()->find($user->getKey());

            if ($state === null) {
                $state = SecondFactorAccountState::query()->create(['user_id' => $user->getKey()]);
                $state->refresh();
            }

            DB::table('second_factor_verification_failures')->insert([
                'id' => (string) Str::ulid(),
                'user_id' => $user->getKey(),
                'factor_id' => $factor?->getKey(),
                'operation' => $operation,
                'source_fingerprint' => $this->sourceFingerprint($sourceIp),
                'occurred_at' => $now,
            ]);

            $state->consecutive_failures++;
            $lockAfter = (int) config('administrator-security.verification.lock_after_consecutive_failures');

            if ($state->consecutive_failures >= $lockAfter) {
                $state->locked_until = $now->copy()->addSeconds((int) config('administrator-security.verification.lock_seconds'));
                $state->next_attempt_at = null;
                $state->save();

                return SecondFactorStatus::Locked;
            }

            $delays = config('administrator-security.verification.delay_seconds');
            $index = min($state->consecutive_failures, count($delays)) - 1;
            $state->next_attempt_at = $now->copy()->addSeconds((int) $delays[$index]);
            $state->save();

            return SecondFactorStatus::Invalid;
        });
    }

    public function succeeded(User $user): void
    {
        SecondFactorAccountState::query()->updateOrCreate(
            ['user_id' => $user->getKey()],
            ['consecutive_failures' => 0, 'next_attempt_at' => null, 'locked_until' => null],
        );
    }

    private function sourceFingerprint(string $sourceIp): string
    {
        $key = config('administrator-security.verification.source_fingerprint_key') ?: config('app.key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Second-factor rate-limit fingerprinting is unavailable.');
        }

        return hash_hmac('sha256', $sourceIp, $key);
    }
}
