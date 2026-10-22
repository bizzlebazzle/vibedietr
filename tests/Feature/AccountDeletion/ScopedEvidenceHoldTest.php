<?php

namespace Tests\Feature\AccountDeletion;

use App\Domain\AccountDeletion\AccountLifecycle;
use App\Domain\AccountDeletion\ErasureEvidenceSchema;
use App\Domain\AccountDeletion\PurgeAccount;
use App\Domain\AccountDeletion\ScopedEvidenceHold;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\UsesErasureJournal;
use Tests\TestCase;

class ScopedEvidenceHoldTest extends TestCase
{
    use RefreshDatabase, UsesErasureJournal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSecond();
        config([
            'database.connections.erasure_evidence' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'erasure.evidence_key' => 'base64:'.base64_encode(random_bytes(32)),
            'erasure.privacy_operator' => 'operator:synthetic-privacy',
            'erasure.operator_environment' => 'testing',
        ]);
        DB::purge('erasure_evidence');
        app(ErasureEvidenceSchema::class)->install(DB::connection('erasure_evidence'));
    }

    public function test_only_approved_scoped_evidence_survives_account_purge_and_is_not_in_application_backups(): void
    {
        $user = User::factory()->create();
        $id = (string) Str::ulid();
        $records = $this->records();
        $holds = app(ScopedEvidenceHold::class);
        $holds->open($id, $user->account_generation, 'security_incident', 'incident:synthetic-1', 'approval:owner-1', $records, null);
        $holds->open($id, $user->account_generation, 'security_incident', 'incident:synthetic-1', 'approval:owner-1', $records, null);
        $this->assertSame(1, DB::connection('erasure_evidence')->table('erasure_evidence_holds')->count());
        $this->assertDatabaseCount('erasure_evidence_holds', 0);
        $this->assertStringNotContainsString('synthetic-case-secret', DB::connection('erasure_evidence')->table('erasure_evidence_holds')->value('protected_evidence'));
        $request = app(AccountLifecycle::class)->request($user, waive: true);
        app(PurgeAccount::class)->purge($request->id);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertSame($records, $holds->read($id));
        $this->travel(90)->days();
        $this->assertSame(1, $holds->maintain()['overdue_reviews']);
        $holds->review($id);
        $this->assertSame(0, $holds->maintain()['overdue_reviews']);
        $this->assertSame($records, $holds->read($id));
        $holds->release($id);
        $holds->release($id);
        $this->assertSame(0, DB::connection('erasure_evidence')->table('erasure_evidence_holds')->count());
        $this->expectException(RuntimeException::class);
        $holds->read($id);
    }

    public function test_an_expired_notice_cannot_be_read_and_maintenance_releases_at_its_original_deadline(): void
    {
        $user = User::factory()->create();
        $id = (string) Str::ulid();
        $expiry = now()->utc()->toImmutable()->addDays(2);
        $holds = app(ScopedEvidenceHold::class);
        $holds->open($id, $user->account_generation, 'legal_requirement', 'notice:synthetic-1', 'approval:owner-2', $this->records(), $expiry);
        try {
            $holds->release($id);
            $this->fail('A legal notice was released without validated authority.');
        } catch (RuntimeException $error) {
            $this->assertSame('validated_release_authority_required', $error->getMessage());
        }
        $this->travelTo($expiry);
        try {
            $holds->read($id);
            $this->fail('Expired evidence was served.');
        } catch (RuntimeException $error) {
            $this->assertSame('evidence_access_denied', $error->getMessage());
        }
        $this->assertSame(1, $holds->maintain()['expired']);
        $this->assertSame(0, DB::connection('erasure_evidence')->table('erasure_evidence_holds')->count());
    }

    public function test_a_restored_closed_hold_cannot_reopen_or_serve_evidence(): void
    {
        $user = User::factory()->create();
        $id = (string) Str::ulid();
        $holds = app(ScopedEvidenceHold::class);
        $holds->open($id, $user->account_generation, 'security_incident', 'incident:synthetic-1', 'approval:owner-1', $this->records(), null);
        $row = (array) DB::connection('erasure_evidence')->table('erasure_evidence_holds')->sole();
        $holds->release($id);
        DB::connection('erasure_evidence')->table('erasure_evidence_holds')->insert($row);
        try {
            $holds->read($id);
            $this->fail('Restored closed evidence was served.');
        } catch (RuntimeException $error) {
            $this->assertSame('evidence_access_denied', $error->getMessage());
        }
        $holds->release($id);
        $this->expectException(RuntimeException::class);
        $holds->open($id, $user->account_generation, 'security_incident', 'incident:synthetic-1', 'approval:owner-1', $this->records(), null);
    }

    public function test_provisioning_is_additive_and_wrong_operator_has_no_case_access(): void
    {
        $this->artisan('erasure:provision', ['store' => 'journal'])->assertSuccessful();
        $this->artisan('erasure:provision', ['store' => 'evidence'])->assertSuccessful();
        $user = User::factory()->create();
        $id = (string) Str::ulid();
        $holds = app(ScopedEvidenceHold::class);
        $holds->open($id, $user->account_generation, 'security_incident', 'incident:synthetic-1', 'approval:owner-1', $this->records(), null);
        config(['erasure.privacy_operator' => 'operator:different-case-scope']);
        $this->expectException(RuntimeException::class);
        $holds->read($id);
    }

    public function test_operator_case_file_command_is_bounded_private_and_does_not_echo_evidence(): void
    {
        $user = User::factory()->create();
        $id = (string) Str::ulid();
        $path = tempnam(sys_get_temp_dir(), 'dep08-case-');
        try {
            chmod($path, 0600);
            file_put_contents($path, json_encode(['id' => $id, 'account_generation' => $user->account_generation,
                'category' => 'security_incident', 'authority_reference' => 'incident:synthetic-1',
                'owner_approval_reference' => 'approval:owner-1', 'records' => $this->records()], JSON_THROW_ON_ERROR));
            $this->artisan('erasure:evidence', ['action' => 'open', '--request' => $path, '--owner-approved' => true])
                ->expectsOutput('Protected evidence action completed.')->assertSuccessful();
            $this->assertSame($this->records(), app(ScopedEvidenceHold::class)->read($id));
        } finally {
            unlink($path);
        }
    }

    public function test_tampered_notice_clock_never_authorizes_early_evidence_deletion(): void
    {
        $user = User::factory()->create();
        $id = (string) Str::ulid();
        $holds = app(ScopedEvidenceHold::class);
        $holds->open($id, $user->account_generation, 'legal_requirement', 'notice:synthetic-1', 'approval:owner-2', $this->records(), now()->utc()->toImmutable()->addDays(2));
        DB::connection('erasure_evidence')->table('erasure_evidence_holds')->where('id', $id)->update(['expires_at' => now()->utc()]);
        try {
            $holds->maintain();
            $this->fail('Tampered evidence was deleted.');
        } catch (RuntimeException $error) {
            $this->assertSame('evidence_integrity_invalid', $error->getMessage());
        }
        $this->assertSame(1, DB::connection('erasure_evidence')->table('erasure_evidence_holds')->count());
    }

    public function test_operator_scope_and_independent_key_are_enforced(): void
    {
        $user = User::factory()->create();
        config(['erasure.evidence_key' => config('app.key')]);
        $this->expectException(RuntimeException::class);
        app(ScopedEvidenceHold::class)->open((string) Str::ulid(), $user->account_generation,
            'security_incident', 'incident:synthetic-1', 'approval:owner-1', $this->records(), null);
    }

    private function records(): array
    {
        return [['reference' => 'record:synthetic-1', 'source_timestamp' => '2026-01-01T00:00:00Z',
            'source_expires_at' => '2026-07-01T00:00:00Z', 'data' => ['required_fact' => 'synthetic-case-secret']]];
    }
}
