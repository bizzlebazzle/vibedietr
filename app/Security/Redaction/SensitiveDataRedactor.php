<?php

namespace App\Security\Redaction;

final class SensitiveDataRedactor
{
    public const REDACTED = '[redacted]';

    public function redact(array $context): array
    {
        return $this->walk($context, 0);
    }

    private function walk(array $values, int $depth): array
    {
        if ($depth >= 8) {
            return ['redacted' => self::REDACTED];
        }

        $sensitive = array_map('strtolower', (array) config('security.sensitive_keys', []));
        $safe = [];

        foreach ($values as $key => $value) {
            $normalized = strtolower((string) $key);
            if ($this->isSensitive($normalized, $sensitive)) {
                $safe[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $safe[$key] = $this->walk($value, $depth + 1);
            } elseif (is_object($value) || is_resource($value)) {
                $safe[$key] = self::REDACTED;
            } else {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    /** @param list<string> $sensitive */
    private function isSensitive(string $key, array $sensitive): bool
    {
        foreach ($sensitive as $part) {
            if ($key === $part || str_contains($key, $part)) {
                return true;
            }
        }

        return false;
    }
}
