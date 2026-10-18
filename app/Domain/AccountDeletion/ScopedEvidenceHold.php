<?php

namespace App\Domain\AccountDeletion;

use App\Audit\AuditActor;
use App\Audit\AuditEventRecorder;
use App\Audit\AuditSubject;
use App\Audit\Enums\AuditAction;
use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Uid\Ulid;
use Throwable;

/** Named privacy/security operator only. This is never an administrator content reader. */
final class ScopedEvidenceHold
{
    /** The owner selects and approves exact evidence in the separate protected case process.
     * @param  array<mixed>  $records
     */
    public function open(string $id, string $generation, string $category, string $authority, string $approval, array $records, ?CarbonImmutable $expires): void
    {
        $id = strtoupper($id);
        $generation = strtoupper($generation);
        $expires = $expires?->utc();
        if ($expires !== null && $expires->micro !== 0) {
            throw new RuntimeException('evidence_deadline_precision_invalid');
        }
        $operator = $this->operator();
        if (! Str::isUlid($id) || ! Str::isUlid($generation)
            || ! in_array($category, ['security_incident', 'proceedings', 'legal_requirement', 'defined_dispute'], true)
            || ! preg_match('/^[A-Za-z0-9:_-]{8,128}$/D', $authority)
            || ! preg_match('/^[A-Za-z0-9:_-]{8,128}$/D', $approval)
            || ! array_is_list($records) || $records === [] || count($records) > 100
            || ($expires !== null && ! $expires->isFuture())) {
            throw new RuntimeException('evidence_hold_invalid');
        }
        foreach ($records as $record) {
            $fields = ['reference', 'source_timestamp', 'source_expires_at', 'data'];
            if (! is_array($record) || array_diff(array_keys($record), $fields) !== [] || array_diff($fields, array_keys($record)) !== []
                || ! is_string($record['reference']) || ! preg_match('/^[A-Za-z0-9:_-]{8,128}$/D', $record['reference'])
                || ! is_array($record['data'])) {
                throw new RuntimeException('evidence_scope_invalid');
            }
            if (! is_string($record['source_timestamp'])) {
                throw new RuntimeException('evidence_source_clock_invalid');
            }
            foreach (['source_timestamp', 'source_expires_at'] as $field) {
                if ($record[$field] !== null && (! is_string($record[$field]) || ! $this->instant($record[$field]))) {
                    throw new RuntimeException('evidence_source_clock_invalid');
                }
            }
        }
        $this->assertData($records);
        $json = json_encode($records, JSON_THROW_ON_ERROR);
        if (strlen($json) > 65536) {
            throw new RuntimeException('evidence_scope_too_large');
        }
        app(ProtectedErasureJournal::class)->repair();
        if ($this->latest($id) === 'hold_released') {
            throw new RuntimeException('evidence_hold_identity_reused');
        }
        $db = $this->store();
        $cipher = $this->cipher();
        $db->transaction(function () use ($db, $cipher, $id, $generation, $category, $authority, $approval, $operator, $records, $expires): void {
            $row = $db->table('erasure_evidence_holds')->where('id', $id)->lockForUpdate()->first();
            if ($row) {
                $prior = json_decode($cipher->decryptString($row->protected_evidence), true, flags: JSON_THROW_ON_ERROR);
                if ($row->account_generation !== $generation || $row->category !== $category
                    || $row->authority_reference !== $authority || $row->owner_approval_reference !== $approval
                    || $row->operator_reference !== $operator || $prior['records'] !== $records
                    || (($row->expires_at === null) !== ($expires === null))
                    || ($expires !== null && ! CarbonImmutable::parse($row->expires_at)->equalTo($expires))) {
                    throw new RuntimeException('evidence_hold_identity_reused');
                }

                return;
            }
            $created = now()->utc()->toImmutable();
            $db->table('erasure_evidence_holds')->insert([
                'id' => $id, 'account_generation' => $generation, 'category' => $category,
                'authority_reference' => $authority, 'owner_approval_reference' => $approval,
                'operator_reference' => $operator, 'state' => 'preparing',
                'created_at' => $created, 'review_at' => $created->addDays(90), 'expires_at' => $expires,
                'protected_evidence' => $cipher->encryptString(json_encode([
                    'id' => $id, 'account_generation' => $generation, 'category' => $category,
                    'authority' => $authority, 'approval' => $approval, 'operator' => $operator, 'records' => $records,
                    'created_at' => $created->format('Y-m-d\TH:i:s\Z'), 'review_at' => $created->addDays(90)->format('Y-m-d\TH:i:s\Z'),
                    'expires_at' => $expires?->utc()->format('Y-m-d\TH:i:s\Z'),
                ], JSON_THROW_ON_ERROR)),
            ]);
        });
        $row = $db->table('erasure_evidence_holds')->where('id', $id)->first();
        app(ProtectedErasureJournal::class)->append($this->operation($id, 'opened'), $this->intent($row, 'hold_started', $row->expires_at ?? CarbonImmutable::parse($row->created_at)->addDays(90)->format('Y-m-d H:i:s')));
        $this->audit($id, 'hold_opened');
        $db->table('erasure_evidence_holds')->where('id', $id)->update(['state' => 'active']);
    }

    /** @return list<array<string, mixed>> */
    public function read(string $id): array
    {
        $id = strtoupper($id);
        $operator = $this->operator();
        $row = $this->store()->table('erasure_evidence_holds')->where('id', $id)->first();
        if (! $row || $row->operator_reference !== $operator || $row->state !== 'active'
            || ($row->expires_at !== null && CarbonImmutable::parse($row->expires_at)->lessThanOrEqualTo(now()->utc()))) {
            throw new RuntimeException('evidence_access_denied');
        }
        if ($this->latest($id) !== 'hold_started') {
            throw new RuntimeException('evidence_access_denied');
        }
        $payload = $this->payload($row);
        $this->audit($id, 'hold_read');

        return $payload['records'];
    }

    public function review(string $id): void
    {
        $id = strtoupper($id);
        $this->read($id);
        $this->audit($id, 'hold_reviewed');
        $row = $this->store()->table('erasure_evidence_holds')->where('id', $id)->first();
        $payload = $this->payload($row);
        $next = now()->utc()->toImmutable()->addDays(90);
        $payload['review_at'] = $next->format('Y-m-d\TH:i:s\Z');
        $this->store()->table('erasure_evidence_holds')->where('id', $id)->update([
            'review_at' => $next, 'protected_evidence' => $this->cipher()->encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
        ]);
    }

    public function release(string $id, ?string $releaseAuthority = null): void
    {
        $id = strtoupper($id);
        $operator = $this->operator();
        $row = $this->store()->table('erasure_evidence_holds')->where('id', $id)->first();
        if (! $row) {
            return;
        }
        if ($row->operator_reference !== $operator) {
            throw new RuntimeException('evidence_access_denied');
        }
        $this->payload($row);
        if ($row->category === 'legal_requirement' && $row->expires_at !== null && CarbonImmutable::parse($row->expires_at)->isFuture()
            && (! is_string($releaseAuthority) || ! preg_match('/^[A-Za-z0-9:_-]{8,128}$/D', $releaseAuthority))) {
            throw new RuntimeException('validated_release_authority_required');
        }
        $deadline = $row->expires_at !== null && CarbonImmutable::parse($row->expires_at)->isPast()
            ? $row->expires_at : now()->utc()->format('Y-m-d H:i:s');
        app(ProtectedErasureJournal::class)->appendOnce($this->operation($id, 'released'), $this->intent($row, 'hold_released', $deadline));
        $this->audit($id, 'hold_released');
        $this->store()->table('erasure_evidence_holds')->where('id', $id)->delete();
    }

    /** Metadata only; expired evidence is released without opening its contents.
     * @return array{expired: int, overdue_reviews: int}
     */
    public function maintain(): array
    {
        $operator = $this->operator();
        $db = $this->store();
        $expired = 0;
        foreach ($db->table('erasure_evidence_holds')->where('operator_reference', $operator)
            ->where('expires_at', '<=', now()->utc())->pluck('id') as $id) {
            $this->release($id);
            $expired++;
        }

        return ['expired' => $expired, 'overdue_reviews' => $db->table('erasure_evidence_holds')
            ->where('operator_reference', $operator)->where('review_at', '<=', now()->utc())->count()];
    }

    private function latest(string $id): ?string
    {
        $latest = null;
        $journal = app(ProtectedErasureJournal::class);
        foreach ($journal->read($journal->checkpoint()) as $entry) {
            if (in_array('hold_'.$id, $entry['resources'], true) && in_array($entry['state'], ['hold_started', 'hold_released'], true)) {
                $latest = $entry['state'];
            }
        }

        return $latest;
    }

    private function payload(object $row): array
    {
        $payload = json_decode($this->cipher()->decryptString($row->protected_evidence), true, flags: JSON_THROW_ON_ERROR);
        foreach (['id' => 'id', 'account_generation' => 'account_generation', 'category' => 'category',
            'authority' => 'authority_reference', 'approval' => 'owner_approval_reference', 'operator' => 'operator_reference'] as $field => $column) {
            if (($payload[$field] ?? null) !== $row->$column) {
                throw new RuntimeException('evidence_integrity_invalid');
            }
        }
        foreach (['created_at', 'review_at', 'expires_at'] as $field) {
            $value = $row->$field === null ? null : CarbonImmutable::parse($row->$field)->utc()->format('Y-m-d\TH:i:s\Z');
            if (($payload[$field] ?? null) !== $value) {
                throw new RuntimeException('evidence_integrity_invalid');
            }
        }

        return $payload;
    }

    private function operator(): string
    {
        return app(PrivacyOperator::class)->reference();
    }

    private function store(): Connection
    {
        try {
            $db = DB::connection('erasure_evidence');
            if ($db === DB::connection()) {
                throw new RuntimeException;
            }
            if (! app()->environment('testing')) {
                if ($db->getDriverName() !== 'mysql' || $db->getConfig('host') === DB::connection()->getConfig('host')
                    || (int) $db->selectOne('SELECT @@innodb_flush_log_at_trx_commit AS n')->n !== 1) {
                    throw new RuntimeException;
                }
            }

            return $db;
        } catch (Throwable) {
            throw new RuntimeException('protected_evidence_store_unavailable');
        }
    }

    private function cipher(): Encrypter
    {
        $configured = config('erasure.evidence_key');
        $key = is_string($configured) && str_starts_with($configured, 'base64:') ? base64_decode(substr($configured, 7), true) : false;
        foreach ([config('app.key'), config('erasure.encryption_key'), config('erasure.integrity_key')] as $other) {
            $decoded = is_string($other) && str_starts_with($other, 'base64:') ? base64_decode(substr($other, 7), true) : $other;
            if ($key === $decoded) {
                throw new RuntimeException('protected_evidence_key_invalid');
            }
        }
        if (! is_string($key) || strlen($key) !== 32) {
            throw new RuntimeException('protected_evidence_key_invalid');
        }

        return new Encrypter($key, 'AES-256-GCM');
    }

    private function operation(string $id, string $event): string
    {
        return Ulid::fromBinary(substr(hash('sha256', 'evidence:'.$id.':'.$event, true), 0, 16))->toBase32();
    }

    private function intent(object $row, string $state, string $deadline): array
    {
        return ['account_generation' => $row->account_generation, 'correlation_id' => $row->id,
            'installation' => config('erasure.installation'), 'policy' => 'DEC-012/DEC-013/DEC-014/DEC-021/DEC-022',
            'state' => $state, 'deadline' => CarbonImmutable::parse($deadline)->utc()->format('Y-m-d\\TH:i:s\\Z'),
            'resources' => ['hold_'.$row->id]];
    }

    private function audit(string $id, string $event): void
    {
        $operation = $event === 'hold_read' || $event === 'hold_reviewed' ? (string) Str::ulid() : $this->operation($id, $event);
        if (AuditEvent::query()->where('correlation_id', $operation)->exists()) {
            return;
        }
        app(AuditEventRecorder::class)->record(AuditAction::ErasureOperatorEvent,
            AuditActor::externalOperator($this->operator()), AuditSubject::system(),
            ['event' => $event, 'outcome' => 'completed'], $operation, $id);
    }

    private function assertData(array $value, int $depth = 0): void
    {
        if ($depth > 16) {
            throw new RuntimeException('evidence_scope_too_deep');
        }
        foreach ($value as $child) {
            if (is_array($child)) {
                $this->assertData($child, $depth + 1);
            } elseif (is_float($child) || (is_object($child) || is_resource($child))) {
                // Exact decimals and byte evidence use strings, never rounded
                // JSON floats or implicitly serialized application model graphs.
                throw new RuntimeException('evidence_source_representation_invalid');
            }
        }
    }

    private function instant(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z', $value, new \DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d\\TH:i:s\\Z') === $value;
    }
}
