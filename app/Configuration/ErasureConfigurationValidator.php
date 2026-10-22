<?php

namespace App\Configuration;

use Illuminate\Support\ConfigurationUrlParser;
use Throwable;

/** Static deployment checks; live durability/TLS/copy-custody verification remains operator-owned. */
final class ErasureConfigurationValidator
{
    /** @return list<string> */
    public function failures(): array
    {
        $failures = [];
        foreach (['installation', 'journal_generation'] as $name) {
            if (! is_string(config('erasure.'.$name)) || ! preg_match('/^[A-Za-z0-9_-]{16,128}$/D', config('erasure.'.$name))) {
                $failures[] = 'ERASURE installation and journal generation must be explicit non-reusable references.';
            }
        }
        $keys = [];
        foreach (['encryption_key', 'integrity_key', 'evidence_key'] as $name) {
            $key = $this->key(config('erasure.'.$name), 32);
            if ($key === null) {
                $failures[] = 'ERASURE journal encryption, integrity and evidence keys must each contain 32 bytes.';
            } else {
                $keys[] = $key;
            }
        }
        $appKey = config('app.key');
        $appKey = is_string($appKey) && str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7), true) : $appKey;
        if (count(array_unique($keys)) !== count($keys) || in_array($appKey, $keys, true)) {
            $failures[] = 'ERASURE journal and evidence keys must be distinct from each other and APP_KEY.';
        }
        foreach (['release_public_key', 'retirement_public_key'] as $name) {
            if ($this->key(config('erasure.'.$name), SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) === null) {
                $failures[] = 'ERASURE release and retirement public verification keys must be provisioned.';
            }
        }
        $names = config('erasure.journal_connections');
        if (! is_array($names) || count($names) !== 2 || $names !== ['erasure_primary', 'erasure_mirror']) {
            $failures[] = 'ERASURE requires the two dedicated journal connections.';

            return array_values(array_unique($failures));
        }
        try {
            $parser = new ConfigurationUrlParser;
            $application = $parser->parseConfiguration(config('database.connections.'.config('database.default')));
            $hosts = [];
            foreach ([...$names, 'erasure_evidence'] as $name) {
                $settings = config('database.connections.'.$name);
                if (! is_array($settings) || empty($settings['url'])) {
                    throw new \RuntimeException;
                }
                $store = $parser->parseConfiguration($settings);
                if (($store['driver'] ?? null) !== 'mysql' || empty($store['host']) || empty($store['database'])
                    || empty($store['username']) || empty($store['password'])
                    || $store['host'] === ($application['host'] ?? null)) {
                    throw new \RuntimeException;
                }
                if ($name !== 'erasure_evidence') {
                    $hosts[] = $store['host'];
                }
            }
            if (count(array_unique($hosts)) !== 2) {
                throw new \RuntimeException;
            }
        } catch (Throwable) {
            // Neither URLs, credentials nor driver error strings belong in readiness output.
            $failures[] = 'ERASURE requires explicit external MySQL stores; both journals must use separate hosts from each other and the application.';
        }

        return array_values(array_unique($failures));
    }

    private function key(mixed $value, int $length): ?string
    {
        if (! is_string($value) || ! str_starts_with($value, 'base64:')) {
            return null;
        }
        $bytes = base64_decode(substr($value, 7), true);

        return is_string($bytes) && strlen($bytes) === $length ? $bytes : null;
    }
}
