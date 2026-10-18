<?php

namespace Tests\Feature\AccountDeletion;

use App\Domain\AccountDeletion\ErasureCheckpoint;
use App\Domain\AccountDeletion\ErasureJournalSchema;
use App\Domain\AccountDeletion\JournalUnavailable;
use App\Domain\AccountDeletion\ProtectedErasureJournal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProtectedErasureJournalTest extends TestCase
{
    private ProtectedErasureJournal $journal;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['journal_test_primary', 'journal_test_mirror'] as $name) {
            $settings = $this->journalDriver() === 'mysql'
                ? array_merge(config('database.connections.mysql'), ['prefix' => $name.'_'])
                : ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
            if ($this->journalDriver() === 'mysql' && ($settings['database'] ?? null) !== 'testing') {
                $this->fail('Journal protocol tests require the disposable testing database.');
            }
            config(['database.connections.'.$name => $settings]);
            DB::purge($name);
            app(ErasureJournalSchema::class)->install(DB::connection($name));
        }
        config([
            'erasure.journal_connections' => ['journal_test_primary', 'journal_test_mirror'],
            'erasure.journal_generation' => 'synthetic-generation-01',
            'erasure.encryption_key' => 'base64:'.base64_encode(random_bytes(32)),
            'erasure.integrity_key' => 'base64:'.base64_encode(random_bytes(32)),
        ]);
        $this->journal = app(ProtectedErasureJournal::class);
    }

    protected function tearDown(): void
    {
        foreach (['journal_test_primary', 'journal_test_mirror'] as $name) {
            DB::connection($name)->getSchemaBuilder()->dropIfExists('erasure_journal_entries');
            DB::purge($name);
        }
        parent::tearDown();
    }

    public function test_acknowledged_intent_is_encrypted_on_both_stores_and_replayed_without_clock_changes(): void
    {
        $intent = $this->intent();
        $checkpoint = $this->journal->append((string) Str::ulid(), $intent);
        $this->assertSame(1, $checkpoint->sequence);
        foreach (['journal_test_primary', 'journal_test_mirror'] as $name) {
            $row = DB::connection($name)->table('erasure_journal_entries')->sole();
            $this->assertStringNotContainsString($intent['account_generation'], $row->ciphertext);
            $this->assertStringNotContainsString($intent['deadline'], $row->ciphertext);
        }
        $this->travel(31)->days();
        $this->assertEquals([$intent], $this->journal->read($checkpoint));
    }

    public function test_retry_repairs_a_primary_only_intent_before_acknowledgement(): void
    {
        $mirror = DB::connection('journal_test_mirror');
        if ($this->journalDriver() === 'mysql') {
            $mirror->statement('ALTER TABLE journal_test_mirror_erasure_journal_entries ADD CONSTRAINT dep08_reject_write CHECK (sequence < 1)');
        } else {
            $mirror->unprepared("CREATE TRIGGER dep08_reject_write BEFORE INSERT ON erasure_journal_entries BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        }
        $operation = (string) Str::ulid();
        try {
            $this->journal->append($operation, $this->intent());
            $this->fail('Mirror failure was acknowledged.');
        } catch (JournalUnavailable $exception) {
            $this->assertSame('erasure_journal_unavailable', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $this->assertSame(1, DB::connection('journal_test_primary')->table('erasure_journal_entries')->count());
        $this->assertSame(0, $mirror->table('erasure_journal_entries')->count());
        if ($this->journalDriver() === 'mysql') {
            $mirror->statement('ALTER TABLE journal_test_mirror_erasure_journal_entries DROP CHECK dep08_reject_write');
        } else {
            $mirror->unprepared('DROP TRIGGER dep08_reject_write');
        }
        $checkpoint = $this->journal->append($operation, $this->intent());
        $this->assertCount(1, $this->journal->read($checkpoint));
        $this->assertEquals($checkpoint, $this->journal->append($operation, $this->intent()));
        $this->assertSame(1, $mirror->table('erasure_journal_entries')->count());
    }

    public function test_reusing_an_operation_identity_cannot_change_its_deadline_or_state(): void
    {
        $operation = (string) Str::ulid();
        $this->journal->append($operation, $this->intent());
        $changed = $this->intent();
        $changed['state'] = 'recovered';
        $this->expectException(JournalUnavailable::class);
        $this->journal->append($operation, $changed);
    }

    public function test_current_anchor_detects_matching_truncated_replicas(): void
    {
        $this->journal->append((string) Str::ulid(), $this->intent());
        $checkpoint = $this->journal->append((string) Str::ulid(), $this->intent());
        foreach (['journal_test_primary', 'journal_test_mirror'] as $name) {
            DB::connection($name)->table('erasure_journal_entries')->where('sequence', 2)->delete();
        }
        $this->expectException(JournalUnavailable::class);
        $this->journal->read($checkpoint);
    }

    public function test_tampered_payload_fails_closed_even_when_both_replicas_match(): void
    {
        $checkpoint = $this->journal->append((string) Str::ulid(), $this->intent());
        foreach (['journal_test_primary', 'journal_test_mirror'] as $name) {
            DB::connection($name)->table('erasure_journal_entries')->update(['ciphertext' => 'tampered']);
        }
        $this->expectException(JournalUnavailable::class);
        $this->journal->read($checkpoint);
    }

    public function test_missing_replica_never_becomes_an_empty_successful_journal(): void
    {
        $checkpoint = $this->journal->append((string) Str::ulid(), $this->intent());
        DB::connection('journal_test_mirror')->getSchemaBuilder()->drop('erasure_journal_entries');
        $this->expectException(JournalUnavailable::class);
        $this->journal->read($checkpoint);
    }

    public function test_mirror_ahead_requires_operator_reconciliation(): void
    {
        $this->journal->append((string) Str::ulid(), $this->intent());
        DB::connection('journal_test_primary')->table('erasure_journal_entries')->delete();
        $this->expectException(JournalUnavailable::class);
        $this->journal->append((string) Str::ulid(), $this->intent());
    }

    #[DataProvider('invalidConfiguration')]
    public function test_invalid_configuration_fails_without_application_database_fallback(string $key, mixed $value): void
    {
        config(['erasure.'.$key => $value]);
        $this->expectException(JournalUnavailable::class);
        $this->journal->append((string) Str::ulid(), $this->intent());
    }

    public static function invalidConfiguration(): array
    {
        return [
            'no encryption key' => ['encryption_key', null],
            'bad integrity key' => ['integrity_key', 'base64:invalid'],
            'unversioned generation' => ['journal_generation', null],
            'same connection twice' => ['journal_connections', ['journal_test_primary', 'journal_test_primary']],
            'application connection' => ['journal_connections', ['mysql', 'journal_test_mirror']],
        ];
    }

    public function test_journal_rejects_arbitrary_private_fields_before_persistence(): void
    {
        $intent = $this->intent();
        $intent['email'] = 'synthetic@example.test';
        try {
            $this->journal->append((string) Str::ulid(), $intent);
            $this->fail('Private input accepted.');
        } catch (JournalUnavailable) {
            $this->assertSame(0, DB::connection('journal_test_primary')->table('erasure_journal_entries')->count());
        }
    }

    public function test_anchor_cannot_cross_storage_generations(): void
    {
        $checkpoint = $this->journal->append((string) Str::ulid(), $this->intent());
        $this->expectException(JournalUnavailable::class);
        $this->journal->read(new ErasureCheckpoint('another-generation-02', 1, $checkpoint->digest));
    }

    public function test_raw_application_key_cannot_be_reused_for_journal_encryption(): void
    {
        $raw = str_repeat('k', 32);
        config(['app.key' => $raw, 'erasure.encryption_key' => 'base64:'.base64_encode($raw)]);
        $this->expectException(JournalUnavailable::class);
        $this->journal->append((string) Str::ulid(), $this->intent());
    }

    public function test_nonexistent_calendar_deadline_is_rejected(): void
    {
        $intent = $this->intent();
        $intent['deadline'] = '2026-02-30T12:00:00Z';
        $this->expectException(JournalUnavailable::class);
        $this->journal->append((string) Str::ulid(), $intent);
    }

    protected function journalDriver(): string
    {
        return 'sqlite';
    }

    /** @return array<string, mixed> */
    private function intent(): array
    {
        return [
            'account_generation' => 'synthetic-account-generation',
            'installation' => 'synthetic-installation-01',
            'correlation_id' => 'synthetic-correlation-01',
            'policy' => 'DEC-012/DEC-013/DEC-014/DEC-021',
            'deadline' => '2026-11-17T12:00:00Z',
            'state' => 'requested',
            'resources' => ['synthetic-resource-01'],
        ];
    }
}
