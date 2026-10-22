<?php

namespace App\Domain\AccountDeletion;

/** Separate operator attestation key; never an ordinary administrator or web credential. */
final class ErasureOperationProof
{
    public function sign(string $purpose, array $facts): array
    {
        app(PrivacyOperator::class)->reference();
        $secret = $this->key('retirement_signing_key', SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);
        $public = $this->key('retirement_public_key', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        if (! hash_equals($public, sodium_crypto_sign_publickey_from_secretkey($secret))) {
            throw new JournalUnavailable;
        }
        $payload = json_encode(['purpose' => $purpose, 'installation' => config('erasure.installation'), 'facts' => $facts], JSON_THROW_ON_ERROR);
        $signature = sodium_crypto_sign_detached($payload, $secret);
        sodium_memzero($secret);

        return ['payload' => $payload, 'signature' => base64_encode($signature)];
    }

    public function verify(array $proof, string $purpose): array
    {
        $payload = $proof['payload'] ?? null;
        $signature = base64_decode($proof['signature'] ?? '', true);
        if (! is_string($payload) || strlen($payload) > 65536 || ! is_string($signature)
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($signature, $payload, $this->key('retirement_public_key', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES))) {
            throw new JournalUnavailable;
        }
        $decoded = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
        if (($decoded['purpose'] ?? null) !== $purpose || ($decoded['installation'] ?? null) !== config('erasure.installation')
            || ! is_array($decoded['facts'] ?? null)) {
            throw new JournalUnavailable;
        }

        return $decoded['facts'];
    }

    private function key(string $name, int $length): string
    {
        $value = config('erasure.'.$name);
        $key = is_string($value) && str_starts_with($value, 'base64:') ? base64_decode(substr($value, 7), true) : false;
        if (! is_string($key) || strlen($key) !== $length) {
            throw new JournalUnavailable;
        }

        return $key;
    }
}
