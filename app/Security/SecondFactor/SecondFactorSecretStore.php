<?php

namespace App\Security\SecondFactor;

use Illuminate\Support\Facades\Crypt;
use SensitiveParameter;

final class SecondFactorSecretStore
{
    public function encrypt(#[SensitiveParameter] string $secret): string
    {
        return Crypt::encryptString($secret);
    }

    public function decrypt(#[SensitiveParameter] string $ciphertext): string
    {
        return Crypt::decryptString($ciphertext);
    }
}
