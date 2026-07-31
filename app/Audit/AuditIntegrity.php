<?php

namespace App\Audit;

use App\Models\AuditEvent;
use Carbon\CarbonInterface;
use RuntimeException;

final class AuditIntegrity
{
    /** @param array<string, mixed> $attributes */
    public function sign(array $attributes): string
    {
        return hash_hmac('sha256', $this->canonicalJson($attributes), $this->currentKey());
    }

    public function verify(AuditEvent $event): bool
    {
        $canonical = $this->canonicalJson($this->attributesFor($event));

        foreach ($this->keys() as $key) {
            if (hash_equals($event->integrity_hash, hash_hmac('sha256', $canonical, $key))) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function attributesFor(AuditEvent $event): array
    {
        return [
            'id' => $event->getKey(),
            'action' => $event->action->value,
            'purpose' => $event->purpose->value,
            'retention_class' => $event->retention_class->value,
            'actor_type' => $event->actor_type->value,
            'actor_identity_id' => $event->actor_identity_id,
            'subject_type' => $event->subject_type->value,
            'subject_identity_id' => $event->subject_identity_id,
            'subject_identifier' => $event->subject_identifier,
            'occurred_at' => $this->formatTimestamp($event->occurred_at),
            'correlation_id' => $event->correlation_id,
            'evidence_reference' => $event->evidence_reference,
            'schema_version' => $event->schema_version,
            'payload' => $event->payload,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function canonicalJson(array $attributes): string
    {
        return json_encode(
            $this->sortRecursively($attributes),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $child) {
            $value[$key] = $this->sortRecursively($child);
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function formatTimestamp(CarbonInterface|string $timestamp): string
    {
        if (is_string($timestamp)) {
            return $timestamp;
        }

        return $timestamp->copy()->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    private function currentKey(): string
    {
        return $this->normalizeKey((string) config('app.key'));
    }

    /** @return list<string> */
    private function keys(): array
    {
        $configured = [config('app.key'), ...config('app.previous_keys', [])];
        $keys = [];

        foreach ($configured as $key) {
            if (is_string($key) && $key !== '') {
                $keys[] = $this->normalizeKey($key);
            }
        }

        if ($keys === []) {
            throw new RuntimeException('Audit integrity protection requires an application key.');
        }

        return $keys;
    }

    private function normalizeKey(string $key): string
    {
        if ($key === '') {
            throw new RuntimeException('Audit integrity protection requires an application key.');
        }

        if (! str_starts_with($key, 'base64:')) {
            return $key;
        }

        $decoded = base64_decode(substr($key, 7), true);

        if ($decoded === false) {
            throw new RuntimeException('The configured application key is invalid.');
        }

        return $decoded;
    }
}
