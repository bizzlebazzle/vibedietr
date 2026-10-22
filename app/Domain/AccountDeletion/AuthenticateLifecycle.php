<?php

namespace App\Domain\AccountDeletion;

use App\Models\User;
use App\Security\SecondFactor\SecondFactorVerifier;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

final class AuthenticateLifecycle
{
    public function verify(User $user, #[SensitiveParameter] string $password, #[SensitiveParameter] string $code, string $operation, string $ip): void
    {
        $valid = Hash::check($password, $user->getAuthPassword());
        if ($valid && ($user->hasConfirmedSecondFactor() || $user->getRawOriginal('is_administrator'))) {
            $valid = app(SecondFactorVerifier::class)->verify($user, $code, $operation, $ip)->succeeded();
        }
        if (! $valid) {
            throw ValidationException::withMessages(['password' => 'The account credentials could not be accepted.']);
        }
    }
}
