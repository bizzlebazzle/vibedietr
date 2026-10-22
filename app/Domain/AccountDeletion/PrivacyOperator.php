<?php

namespace App\Domain\AccountDeletion;

use RuntimeException;

final class PrivacyOperator
{
    public function reference(): string
    {
        $reference = config('erasure.privacy_operator');
        if (! app()->runningInConsole() || config('erasure.operator_environment') !== app()->environment()
            || ! is_string($reference) || ! preg_match('/^[A-Za-z0-9:_-]{8,64}$/D', $reference)) {
            throw new RuntimeException('privacy_operator_required');
        }

        return $reference;
    }
}
