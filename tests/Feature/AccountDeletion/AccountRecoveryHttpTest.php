<?php

namespace Tests\Feature\AccountDeletion;

use App\Domain\AccountDeletion\AccountLifecycle;
use App\Domain\AccountDeletion\PurgeAccount;
use App\Domain\RecipeImports\RecipeImportProcessor;
use App\Jobs\ProcessAccountDeletion;
use App\Jobs\ProcessPastedRecipeImport;
use App\Models\AccountDeletion;
use App\Models\RecipeImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\UsesErasureJournal;
use Tests\TestCase;

class AccountRecoveryHttpTest extends TestCase
{
    use RefreshDatabase, UsesErasureJournal;

    public function test_recovery_does_not_restart_expired_imports_or_discard_private_review_source(): void
    {
        $this->freezeSecond();
        Queue::fake();
        $owner = User::factory()->create();
        $import = RecipeImport::factory()->for($owner, 'owner')->create(['created_at' => now()->subDays(8)]);
        $unrelated = RecipeImport::factory()->create(['created_at' => now()->subDays(8)]);
        $text = $import->source_text;
        $lifecycle = app(AccountLifecycle::class);
        $lifecycle->request($owner);
        $lifecycle->recover($owner);
        $this->assertTrue($owner->isActive());
        $this->assertEquals(now()->subDay(), $import->fresh()->failed_at);
        $this->assertSame($text, $import->fresh()->source_text);
        $this->assertSame('pending', $unrelated->fresh()->status->value);
        Queue::assertNotPushed(ProcessPastedRecipeImport::class);
        $this->assertNull(app(RecipeImportProcessor::class)->process($import->id));
        $this->assertDatabaseMissing('recipes', ['user_id' => $owner->id]);
    }

    public function test_recovery_requires_credentials_and_explicit_confirmation_and_restores_a_session(): void
    {
        $owner = User::factory()->create();
        app(AccountLifecycle::class)->request($owner);
        $this->get(route('account-recovery'))->assertOk()->assertSee('A waiver cannot be undone');
        $this->post(route('account-recovery.store'), ['email' => $owner->email, 'password' => 'wrong',
            'operation' => 'recover', 'confirmed' => '1'])->assertSessionHasErrors('password');
        $this->assertFalse($owner->isActive());
        $this->post(route('account-recovery.store'), ['email' => $owner->email, 'password' => 'password',
            'operation' => 'recover'])->assertSessionHasErrors('confirmed');
        $this->post(route('account-recovery.store'), ['email' => $owner->email, 'password' => 'password',
            'operation' => 'recover', 'confirmed' => '1'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($owner);
        $this->assertTrue($owner->isActive());
    }

    public function test_authenticated_waiver_queues_final_purge_and_worker_runs_idempotently(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $request = app(AccountLifecycle::class)->request($owner);
        $this->post(route('account-recovery.store'), ['email' => $owner->email, 'password' => 'password',
            'operation' => 'waive', 'confirmed' => '1'])->assertRedirect(route('account-recovery'));
        $this->assertGuest();
        Queue::assertPushed(ProcessAccountDeletion::class, fn ($job) => $job->deletionId === $request->id && $job->correlationId === $request->correlation_id);
        $job = new ProcessAccountDeletion($request->id, $request->correlation_id);
        $this->assertStringNotContainsString($owner->email, serialize($job));
        $this->assertStringNotContainsString('password', serialize($job));
        $job->handle(app(PurgeAccount::class));
        $job->handle(app(PurgeAccount::class));
        $this->assertDatabaseMissing('users', ['id' => $owner->id]);
        $this->assertDatabaseCount('account_erasure_receipts', 1);
    }

    public function test_scheduler_dispatches_due_and_incomplete_work_without_resetting_deadlines(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $request = app(AccountLifecycle::class)->request($owner);
        $this->artisan('accounts:process-deletions')->assertSuccessful();
        Queue::assertNothingPushed();
        $this->travelTo($request->deadline);
        $this->artisan('accounts:process-deletions')->assertSuccessful();
        Queue::assertPushed(ProcessAccountDeletion::class, 1);
        $this->assertSame($request->deadline->timestamp, $request->fresh()->deadline->timestamp);
    }

    public function test_processing_pauses_while_inactive_and_resumes_with_original_operation_identity(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $import = RecipeImport::factory()->create(['user_id' => $owner->id]);
        app(AccountLifecycle::class)->request($owner);
        $processor = app(RecipeImportProcessor::class);
        $this->assertNull($processor->process($import->id));
        $this->assertSame('pending', $import->fresh()->status->value);
        app(AccountLifecycle::class)->recover($owner);
        Queue::assertPushed(ProcessPastedRecipeImport::class, fn ($job) => $job->importId === $import->id && $job->correlationId === $import->correlation_id);
        $this->assertNotNull($processor->process($import->id));
        $this->assertSame('review_ready', $import->fresh()->status->value);
    }

    public function test_waiver_after_expiry_cannot_reset_the_original_erasure_deadline(): void
    {
        $owner = User::factory()->create();
        $request = app(AccountLifecycle::class)->request($owner);
        $this->travelTo($request->deadline->addDay());
        app(AccountLifecycle::class)->waive($owner);
        $this->assertSame($request->deadline->timestamp, $request->fresh()->deadline->timestamp);
        $this->assertSame('waived', AccountDeletion::query()->sole()->reason);
    }
}
