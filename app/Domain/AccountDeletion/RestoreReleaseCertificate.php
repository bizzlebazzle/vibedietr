<?php

namespace App\Domain\AccountDeletion;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Operator-only Ed25519 signing; serving processes need only the verification key. */
final class RestoreReleaseCertificate
{
    public const POLICY = 'DEC-012/DEC-013/DEC-014/DEC-021/DEC-022';

    public function required(): bool
    {
        return config('erasure.restore_target') !== null && config('erasure.restore_target') !== '';
    }

    public function context(): array
    {
        $target = config('erasure.restore_target');
        $set = config('erasure.restore_set');
        $digest = config('erasure.restore_manifest_digest');
        $release = config('observability.release');
        if (! is_string($target) || ! Str::isUlid($target)
            || ! is_string($set) || ! preg_match('/^[a-f0-9]{64}$/D', $set)
            || ! is_string($digest) || ! preg_match('/^[a-f0-9]{64}$/D', $digest)
            || ! is_string($release) || $release === 'unknown' || ! preg_match('/^[A-Za-z0-9_.-]{1,128}$/D', $release)) {
            throw new JournalUnavailable;
        }
        $db = DB::connection();

        return ['target' => $target, 'set' => $set, 'manifest_digest' => $digest,
            'installation' => config('erasure.installation'), 'release' => $release, 'policy' => self::POLICY,
            'database' => hash('sha256', json_encode([$db->getConfig('host'), $db->getConfig('port'), $db->getDatabaseName()], JSON_THROW_ON_ERROR)),
            'objects' => hash('sha256', json_encode([config('filesystems.disks.s3.endpoint'), config('filesystems.disks.s3.bucket')], JSON_THROW_ON_ERROR))];
    }

    public function sign(array $proof): array
    {
        app(PrivacyOperator::class)->reference();
        if (($proof['context'] ?? null) !== $this->context() || ! in_array($proof['phase'] ?? null, ['prepared', 'released'], true)) {
            throw new JournalUnavailable;
        }
        $secret = $this->key('erasure.release_signing_key', SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);
        $public = $this->key('erasure.release_public_key', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        if (! hash_equals($public, sodium_crypto_sign_publickey_from_secretkey($secret))) {
            throw new JournalUnavailable;
        }
        $payload = json_encode($proof, JSON_THROW_ON_ERROR);
        $signature = sodium_crypto_sign_detached($payload, $secret);
        sodium_memzero($secret);

        return ['payload' => $payload, 'signature' => base64_encode($signature)];
    }

    public function verify(array $certificate, string $phase = 'released'): array
    {
        $payload = $certificate['payload'] ?? null;
        $signature = base64_decode($certificate['signature'] ?? '', true);
        if (! is_string($payload) || ! is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($signature, $payload, $this->key('erasure.release_public_key', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES))) {
            throw new JournalUnavailable;
        }
        $proof = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
        if (($proof['phase'] ?? null) !== $phase || ($proof['context'] ?? null) !== $this->context()) {
            throw new JournalUnavailable;
        }

        return $proof;
    }

    public function assertReleased(): void
    {
        if (! $this->required()) {
            return;
        }
        $file = config('erasure.release_certificate');
        if (! is_string($file) || ! is_file($file) || is_link($file) || filesize($file) > 32768) {
            throw new JournalUnavailable;
        }
        $this->verify(json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR));
    }

    private function key(string $name, int $bytes): string
    {
        $value = config($name);
        $key = is_string($value) && str_starts_with($value, 'base64:') ? base64_decode(substr($value, 7), true) : false;
        if (! is_string($key) || strlen($key) !== $bytes) {
            throw new JournalUnavailable;
        }

        return $key;
    }
}
