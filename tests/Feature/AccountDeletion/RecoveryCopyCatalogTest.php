<?php

namespace Tests\Feature\AccountDeletion;

use App\Domain\AccountDeletion\ErasureJournalSchema;
use App\Domain\AccountDeletion\ErasureJournalState;
use App\Domain\AccountDeletion\ErasureOperationProof;
use App\Domain\AccountDeletion\JournalGenerationRotation;
use App\Domain\AccountDeletion\JournalUnavailable;
use App\Domain\AccountDeletion\ProtectedErasureJournal;
use App\Domain\AccountDeletion\RecoveryCopyCatalog;
use App\Domain\AccountDeletion\RecoveryRetentionHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesErasureJournal;
use Tests\TestCase;

class RecoveryCopyCatalogTest extends TestCase
{
    use UsesErasureJournal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSecond();
        $pair = sodium_crypto_sign_keypair();
        config(['erasure.privacy_operator' => 'synthetic-retirement-operator', 'erasure.operator_environment' => 'testing',
            'erasure.retirement_signing_key' => 'base64:'.base64_encode(sodium_crypto_sign_secretkey($pair)),
            'erasure.retirement_public_key' => 'base64:'.base64_encode(sodium_crypto_sign_publickey($pair))]);
    }

    public function test_copied_data_inherits_scope_and_cannot_renew_its_capture_clock(): void
    {
        $owner = (string) Str::ulid();
        $parent = (string) Str::ulid();
        $catalog = app(RecoveryCopyCatalog::class);
        $journal = app(ProtectedErasureJournal::class);
        $captured = now()->toImmutable()->subDays(6);
        $catalog->register($parent, 'recovery_set', $captured, str_repeat('a', 64), ['owner_'.$owner], $journal->checkpoint());
        $child = (string) Str::ulid();
        $catalog->register($child, 'recovery_set', $captured, str_repeat('b', 64), ['parent_'.$parent], $journal->checkpoint());
        $copies = $catalog->copies(app(ErasureJournalState::class)->at($journal->checkpoint()));
        $this->assertContains('owner_'.$owner, $copies[$child]['resources']);
        $this->expectException(JournalUnavailable::class);
        $catalog->register((string) Str::ulid(), 'recovery_set', now()->toImmutable(), str_repeat('c', 64), ['parent_'.$parent], $journal->checkpoint());
    }

    public function test_unverified_copy_keeps_completed_erasure_truth_in_the_fresh_generation(): void
    {
        [$owner, $copy] = $this->completedWithCopy();
        $configuration = $this->newStores('retained');
        app(JournalGenerationRotation::class)->prepare($configuration, $this->inventory(), $this->storage($configuration));
        foreach ($configuration as $key => $value) {
            config(['erasure.'.$key => $value]);
        }
        $journal = app(ProtectedErasureJournal::class);
        $entries = app(ErasureJournalState::class)->at($journal->checkpoint());
        $this->assertSame('purged', app(ErasureJournalState::class)->latest($entries)[$owner]['state']);
        $this->assertArrayHasKey($copy, app(RecoveryCopyCatalog::class)->copies($entries));
    }

    public function test_only_verified_all_copy_retirement_allows_linkable_erasure_truth_to_be_omitted(): void
    {
        [$owner, $copy] = $this->completedWithCopy();
        $facts = ['copy_id' => $copy, 'manifest_sha256' => str_repeat('a', 64), 'observations_sha256' => str_repeat('b', 64),
            'mechanism' => 'restic_forget_prune_readcheck', 'verified_at' => now()->utc()->format('Y-m-d\\TH:i:s\\Z'), 'generations' => []];
        app(RecoveryCopyCatalog::class)->retire(app(ErasureOperationProof::class)->sign('copy-retirement', $facts));
        $configuration = $this->newStores('minimized');
        $prepared = app(JournalGenerationRotation::class)->prepare($configuration, $this->inventory(), $this->storage($configuration));
        foreach ($configuration as $key => $value) {
            config(['erasure.'.$key => $value]);
        }
        $journal = app(ProtectedErasureJournal::class);
        $this->assertSame([], app(ErasureJournalState::class)->at($journal->checkpoint()));
        // Rebuilding rows is insufficient: old storage/key/header evidence is mandatory.
        $this->expectException(JournalUnavailable::class);
        app(JournalGenerationRotation::class)->complete($prepared, $this->inventory());
    }

    public function test_incomplete_inventory_or_an_unsigned_observation_cannot_retire_a_copy(): void
    {
        [, $copy] = $this->completedWithCopy();
        $this->expectException(JournalUnavailable::class);
        app(RecoveryCopyCatalog::class)->retire(['payload' => json_encode(['copy_id' => $copy]), 'signature' => base64_encode(random_bytes(64))]);
    }

    public function test_missing_active_copy_in_the_independent_inventory_denies_rebuild(): void
    {
        $this->completedWithCopy();
        $configuration = $this->newStores('incomplete');
        $inventory = $this->inventory([]);
        $this->expectException(JournalUnavailable::class);
        app(JournalGenerationRotation::class)->prepare($configuration, $inventory, $this->storage($configuration));
    }

    public function test_old_stores_keys_headers_and_credentials_must_all_retire_before_cutover(): void
    {
        $journal = app(ProtectedErasureJournal::class);
        $oldGeneration = $journal->checkpoint()->generation;
        $reference = 'generation_'.hash('sha256', $oldGeneration);
        $ids = [];
        foreach (['journal_storage', 'journal_storage', 'journal_key', 'luks_header', 'recovery_credential'] as $kind) {
            $id = (string) Str::ulid();
            $ids[] = $id;
            app(RecoveryCopyCatalog::class)->register($id, $kind, now()->toImmutable()->subDay(), str_repeat('a', 64), [$reference], $journal->checkpoint());
        }
        $configuration = $this->newStores('complete');
        $prepared = app(JournalGenerationRotation::class)->prepare($configuration, $this->inventory(), $this->storage($configuration));
        foreach ($configuration as $key => $value) {
            config(['erasure.'.$key => $value]);
        }
        foreach ($ids as $index => $id) {
            if ($index === count($ids) - 1) {
                try {
                    app(JournalGenerationRotation::class)->complete($prepared, $this->inventory());
                    $this->fail('Cutover accepted an unretired credential copy.');
                } catch (JournalUnavailable) {
                    $this->addToAssertionCount(1);
                }
            }
            $facts = ['copy_id' => $id, 'manifest_sha256' => str_repeat('a', 64), 'observations_sha256' => str_repeat('b', 64),
                'mechanism' => 'synthetic_volatile_generation', 'verified_at' => now()->utc()->format('Y-m-d\\TH:i:s\\Z'), 'generations' => [$reference]];
            app(RecoveryCopyCatalog::class)->retire(app(ErasureOperationProof::class)->sign('copy-retirement', $facts));
        }
        $receipt = app(JournalGenerationRotation::class)->complete($prepared, $this->inventory());
        $facts = app(ErasureOperationProof::class)->verify($receipt, 'journal-generation-disposed');
        $this->assertSame($oldGeneration, $facts['old_generation']);
        $this->assertSame($configuration['journal_generation'], $facts['checkpoint']['generation']);
    }

    public function test_dirty_late_captures_keep_original_erasure_truth_and_overdue_clocks(): void
    {
        [$owner] = $this->completedWithCopy();
        $journal = app(ProtectedErasureJournal::class);
        $id = (string) Str::ulid();
        app(RecoveryCopyCatalog::class)->register($id, 'recovery_set', now()->toImmutable(), str_repeat('f', 64),
            ['owner_'.$owner], $journal->checkpoint());
        $entries = app(ErasureJournalState::class)->at($journal->checkpoint());
        $copy = app(RecoveryCopyCatalog::class)->copies($entries)[$id];
        $this->assertContains('incident_dirty_capture', $copy['resources']);
        $this->travel(9)->days();
        $counts = app(RecoveryRetentionHealth::class)->counts();
        $this->assertSame(2, $counts['copy_expiry_overdue']);
        $this->assertSame(1, $counts['journal_disposal_overdue']);
        $this->assertStringNotContainsString($owner, json_encode($counts));
    }

    private function completedWithCopy(): array
    {
        $owner = (string) Str::ulid();
        $id = (string) Str::ulid();
        $journal = app(ProtectedErasureJournal::class);
        $intent = ['account_generation' => $owner, 'installation' => config('erasure.installation'),
            'correlation_id' => (string) Str::ulid(), 'deadline' => now()->subDay()->utc()->format('Y-m-d\\TH:i:s\\Z'),
            'state' => 'purged', 'policy' => 'DEC-012/DEC-013/DEC-014/DEC-021/DEC-022',
            'resources' => ['request_'.Str::ulid()]];
        $journal->append((string) Str::ulid(), $intent);
        app(RecoveryCopyCatalog::class)->register($id, 'recovery_set', now()->toImmutable()->subDays(2), str_repeat('a', 64),
            ['owner_'.$owner], $journal->checkpoint());

        return [$owner, $id];
    }

    private function newStores(string $suffix): array
    {
        $names = ['copy_rotation_primary_'.$suffix, 'copy_rotation_mirror_'.$suffix];
        foreach ($names as $name) {
            config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
            DB::purge($name);
            app(ErasureJournalSchema::class)->install(DB::connection($name));
        }

        return ['journal_connections' => $names, 'journal_generation' => 'synthetic-copy-generation-'.$suffix,
            'encryption_key' => 'base64:'.base64_encode(random_bytes(32)), 'integrity_key' => 'base64:'.base64_encode(random_bytes(32))];
    }

    private function inventory(?array $reported = null): array
    {
        $head = app(ProtectedErasureJournal::class)->checkpoint();
        $entries = app(ErasureJournalState::class)->at($head);
        $ids = array_keys(array_filter(app(RecoveryCopyCatalog::class)->copies($entries), fn ($copy) => ! ($copy['retired'] ?? false)));

        return app(ErasureOperationProof::class)->sign('complete-copy-inventory', [
            'active_copy_ids' => $reported ?? $ids, 'scope' => 'all-data-journal-key-header-credential-and-host-layers',
            'checkpoint' => ['generation' => $head->generation, 'sequence' => $head->sequence, 'digest' => $head->digest],
            'observations_sha256' => str_repeat('d', 64)]);
    }

    private function storage(array $configuration): array
    {
        $scopes = [];
        foreach ($configuration['journal_connections'] as $name) {
            $connection = DB::connection($name);
            $scopes[] = hash('sha256', json_encode([$connection->getConfig('host'), $connection->getConfig('port'), $connection->getDatabaseName()], JSON_THROW_ON_ERROR));
        }

        return app(ErasureOperationProof::class)->sign('fresh-luks2-journal-storage', [
            'generation' => $configuration['journal_generation'], 'connection_scopes' => $scopes,
            'luks2_uuids' => ['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'],
            'observations_sha256' => str_repeat('e', 64)]);
    }
}
