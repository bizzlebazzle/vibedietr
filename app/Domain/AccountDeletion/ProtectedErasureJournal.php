<?php

namespace App\Domain\AccountDeletion;

use Illuminate\Database\Connection;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Append-only intent replication, not a service-release or disposal authorization.
 * Caller-supplied checkpoint evidence must be independent of application restores.
 */
final class ProtectedErasureJournal
{
    private const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * A primary suffix after mirror failure is durable but unacknowledged.
     * Retrying repairs that suffix before acknowledging any new transition.
     *
     * @param  array<string, mixed>  $intent
     */
    public function append(string $operationId, array $intent): ErasureCheckpoint
    {
        $operationId = strtoupper($operationId);
        $primary = null;
        $locked = false;
        try {
            [$primary, $mirror, $generation, $key, $encrypter] = $this->configuration();
            if (! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $operationId)) {
                throw new JournalUnavailable;
            }
            $json = $this->intentJson($intent);
            if ($primary->getDriverName() === 'mysql') {
                $lock = $primary->selectOne('SELECT GET_LOCK(?, 5) AS acquired', ['erasure-journal:'.$generation]);
                if ((int) ($lock->acquired ?? 0) !== 1) {
                    throw new JournalUnavailable;
                }
                $locked = true;
            }
            $left = $this->validatedRows($primary, $generation, $key, $encrypter);
            $right = $this->validatedRows($mirror, $generation, $key, $encrypter);
            if (count($right) > count($left)) {
                throw new JournalUnavailable;
            }
            foreach ($right as $index => $row) {
                if ($row !== $left[$index]) {
                    throw new JournalUnavailable;
                }
            }
            foreach (array_slice($left, count($right)) as $row) {
                $mirror->transaction(fn () => $mirror->table('erasure_journal_entries')->insert($row));
            }
            foreach ($left as $row) {
                if ($row['operation_id'] === $operationId) {
                    if ($encrypter->decryptString($row['ciphertext']) !== $json) {
                        throw new JournalUnavailable;
                    }

                    return new ErasureCheckpoint($generation, $row['sequence'], $row['digest']);
                }
            }
            $row = [
                'sequence' => count($left) + 1,
                'operation_id' => $operationId,
                'generation' => $generation,
                'previous_digest' => $left === [] ? self::GENESIS : $left[array_key_last($left)]['digest'],
                'ciphertext' => $encrypter->encryptString($json),
            ];
            $row['digest'] = $this->digest($row, $key);
            $primary->transaction(fn () => $primary->table('erasure_journal_entries')->insert($row));
            $mirror->transaction(fn () => $mirror->table('erasure_journal_entries')->insert($row));

            return new ErasureCheckpoint($generation, $row['sequence'], $row['digest']);
        } catch (Throwable) {
            // Driver exceptions can include SQL bindings or credentials.
            throw new JournalUnavailable;
        } finally {
            if ($locked && $primary instanceof Connection) {
                try {
                    $primary->selectOne('SELECT RELEASE_LOCK(?)', ['erasure-journal:'.config('erasure.journal_generation')]);
                } catch (Throwable) {
                    // A lost connection itself releases this connection-scoped lock.
                }
            }
        }
    }

    /**
     * Matching old replicas alone cannot prove freshness. The anchor is mandatory
     * external evidence. Frozen reconciliation/release remains a separate gate.
     *
     * @return list<array<string, mixed>>
     */
    public function read(ErasureCheckpoint $anchor): array
    {
        try {
            [$primary, $mirror, $generation, $key, $encrypter] = $this->configuration();
            if ($anchor->generation !== $generation || $anchor->sequence < 0) {
                throw new JournalUnavailable;
            }
            $left = $this->validatedRows($primary, $generation, $key, $encrypter);
            $right = $this->validatedRows($mirror, $generation, $key, $encrypter);
            if ($anchor->sequence === 0) {
                if ($left !== [] || $right !== [] || ! hash_equals(self::GENESIS, $anchor->digest)) {
                    throw new JournalUnavailable;
                }

                return [];
            }
            if ($left !== $right || ! isset($left[$anchor->sequence - 1])
                || ! hash_equals($left[$anchor->sequence - 1]['digest'], $anchor->digest)) {
                throw new JournalUnavailable;
            }

            return array_map(fn (array $row): array => json_decode(
                $encrypter->decryptString($row['ciphertext']), true, 64, JSON_THROW_ON_ERROR,
            ), $left);
        } catch (Throwable) {
            throw new JournalUnavailable;
        }
    }

    /** Repair only an authenticated durable primary suffix; never guess between divergent stores. */
    public function repair(): ErasureCheckpoint
    {
        try {
            [$primary, , $generation, $key, $encrypter] = $this->configuration();
            $rows = $this->validatedRows($primary, $generation, $key, $encrypter);
            if ($rows !== []) {
                $head = $rows[array_key_last($rows)];
                $this->append($head['operation_id'], json_decode($encrypter->decryptString($head['ciphertext']), true, flags: JSON_THROW_ON_ERROR));
            }

            return $this->checkpoint();
        } catch (Throwable) {
            throw new JournalUnavailable;
        }
    }

    /** Live retries reuse the first durable clock, including an unacknowledged primary suffix.
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>
     */
    public function appendOnce(string $operation, array $intent): array
    {
        $operation = strtoupper($operation);
        try {
            [$primary, , $generation, $key, $encrypter] = $this->configuration();
            foreach ($this->validatedRows($primary, $generation, $key, $encrypter) as $row) {
                if ($row['operation_id'] !== $operation) {
                    continue;
                }
                $stored = json_decode($encrypter->decryptString($row['ciphertext']), true, flags: JSON_THROW_ON_ERROR);
                $candidate = $intent;
                $candidate['deadline'] = $stored['deadline'];
                if ($this->intentJson($candidate) !== $this->intentJson($stored)) {
                    throw new JournalUnavailable;
                }
                $intent = $stored;
                break;
            }
            $this->append($operation, $intent);

            return $intent;
        } catch (Throwable) {
            throw new JournalUnavailable;
        }
    }

    /** Current live head; restore release additionally requires an independent anchor. */
    public function checkpoint(): ErasureCheckpoint
    {
        try {
            [$primary, $mirror, $generation, $key, $encrypter] = $this->configuration();
            $left = $this->validatedRows($primary, $generation, $key, $encrypter);
            $right = $this->validatedRows($mirror, $generation, $key, $encrypter);
            if ($left !== $right) {
                throw new JournalUnavailable;
            }
            if ($left === []) {
                return new ErasureCheckpoint($generation, 0, self::GENESIS);
            }
            $head = $left[array_key_last($left)];

            return new ErasureCheckpoint($generation, $head['sequence'], $head['digest']);
        } catch (Throwable) {
            throw new JournalUnavailable;
        }
    }

    /** @return array{Connection, Connection, string, string, Encrypter} */
    private function configuration(): array
    {
        $names = config('erasure.journal_connections');
        $generation = config('erasure.journal_generation');
        $encryption = $this->key(config('erasure.encryption_key'));
        $integrity = $this->key(config('erasure.integrity_key'));
        if (! is_array($names) || count($names) !== 2 || ! is_string($names[0])
            || ! is_string($names[1]) || $names[0] === $names[1]
            || in_array(config('database.default'), $names, true)
            || ! is_string($generation) || ! preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $generation)
            || hash_equals($encryption, $integrity)) {
            throw new JournalUnavailable;
        }
        $appKey = config('app.key');
        if (is_string($appKey)) {
            $decoded = str_starts_with($appKey, 'base64:')
                ? base64_decode(substr($appKey, 7), true)
                : $appKey;
            if ($decoded === $encryption || $decoded === $integrity) {
                throw new JournalUnavailable;
            }
        }
        $connections = [];
        foreach ($names as $name) {
            $connection = DB::connection($name);
            if (! app()->environment('testing')) {
                if ($connection->getDriverName() !== 'mysql') {
                    throw new JournalUnavailable;
                }
                $durability = $connection->selectOne('SELECT @@innodb_flush_log_at_trx_commit AS flush_mode');
                $table = $connection->selectOne(
                    'SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                    [$connection->getDatabaseName(), $connection->getTablePrefix().'erasure_journal_entries'],
                );
                if ((int) ($durability->flush_mode ?? 0) !== 1 || ($table->engine ?? null) !== 'InnoDB') {
                    throw new JournalUnavailable;
                }
            }
            $connections[] = $connection;
        }
        if (! app()->environment('testing')) {
            $hosts = [
                DB::connection()->getConfig('host'),
                $connections[0]->getConfig('host'),
                $connections[1]->getConfig('host'),
            ];
            if (count(array_unique($hosts)) !== 3 || in_array(null, $hosts, true)) {
                throw new JournalUnavailable;
            }
        } elseif ($connections[0] === $connections[1]) {
            throw new JournalUnavailable;
        }

        return [$connections[0], $connections[1], $generation, $integrity, new Encrypter($encryption, 'AES-256-GCM')];
    }

    private function key(mixed $key): string
    {
        if (! is_string($key) || ! str_starts_with($key, 'base64:')) {
            throw new JournalUnavailable;
        }
        $decoded = base64_decode(substr($key, 7), true);
        if (! is_string($decoded) || strlen($decoded) !== 32) {
            throw new JournalUnavailable;
        }

        return $decoded;
    }

    /** @return list<array<string, mixed>> */
    private function validatedRows(Connection $connection, string $generation, string $key, Encrypter $encrypter): array
    {
        $rows = [];
        $previous = self::GENESIS;
        foreach ($connection->table('erasure_journal_entries')->orderBy('sequence')->cursor() as $record) {
            $row = (array) $record;
            $row['sequence'] = (int) $row['sequence'];
            if ($row['sequence'] !== count($rows) + 1 || $row['generation'] !== $generation
                || $row['previous_digest'] !== $previous
                || ! hash_equals($this->digest($row, $key), $row['digest'])) {
                throw new JournalUnavailable;
            }
            $json = $encrypter->decryptString($row['ciphertext']);
            $intent = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($intent) || $this->intentJson($intent) !== $json) {
                throw new JournalUnavailable;
            }
            $previous = $row['digest'];
            $rows[] = $row;
        }

        return $rows;
    }

    /** @param array<string, mixed> $row */
    private function digest(array $row, string $key): string
    {
        return hash_hmac('sha256', json_encode([
            $row['generation'], $row['sequence'], $row['operation_id'], $row['previous_digest'], $row['ciphertext'],
        ], JSON_THROW_ON_ERROR), $key);
    }

    /** @param array<string, mixed> $intent */
    private function intentJson(array $intent): string
    {
        $allowed = ['account_generation', 'correlation_id', 'deadline', 'installation', 'policy', 'resources', 'state'];
        if (array_diff(array_keys($intent), $allowed) !== []
            || array_diff($allowed, array_keys($intent)) !== []
            || ! in_array($intent['state'], ['requested', 'recovered', 'waived', 'under13', 'purge_started', 'purged', 'inventory', 'plan_suppressed', 'plan_removed', 'hold_started', 'hold_released', 'copy_registered', 'copy_retired'], true)
            || ! in_array($intent['policy'], ['DEC-012/DEC-013/DEC-014/DEC-021', 'DEC-012/DEC-013/DEC-014/DEC-021/DEC-022'], true)
            || ! is_string($intent['deadline'])
            || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $intent['deadline'])
            || ! is_array($intent['resources']) || ! array_is_list($intent['resources'])) {
            throw new JournalUnavailable;
        }
        $deadline = \DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z', $intent['deadline'], new \DateTimeZone('UTC'),
        );
        if ($deadline === false || $deadline->format('Y-m-d\TH:i:s\Z') !== $intent['deadline']) {
            throw new JournalUnavailable;
        }
        foreach (['account_generation', 'correlation_id', 'installation'] as $field) {
            if (! is_string($intent[$field]) || ! preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $intent[$field])) {
                throw new JournalUnavailable;
            }
        }
        foreach ($intent['resources'] as $reference) {
            if (! is_string($reference) || ! preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $reference)) {
                throw new JournalUnavailable;
            }
        }
        ksort($intent);
        $json = json_encode($intent, JSON_THROW_ON_ERROR);
        if (strlen($json) > 65536) {
            throw new JournalUnavailable;
        }

        return $json;
    }
}
