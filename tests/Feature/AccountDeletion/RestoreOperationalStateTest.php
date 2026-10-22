<?php

namespace Tests\Feature\AccountDeletion;

use App\Domain\AccountDeletion\JournalUnavailable;
use App\Domain\AccountDeletion\RestoreOperationalState;
use App\Models\AccountExport;
use App\Models\RecipeImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RestoreOperationalStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_restore_minimizes_processing_metadata_without_deleting_private_review_content_or_reclocking(): void
    {
        $this->freezeSecond();
        config(['erasure.privacy_operator' => 'synthetic-restore-operator', 'erasure.operator_environment' => 'testing']);
        $finished = RecipeImport::factory()->withDraft()->create(['completed_at' => now()->subDays(30), 'source_bytes' => 42, 'manual_retry_count' => 1]);
        $draft = $finished->recipe;
        $abandoned = RecipeImport::factory()->create(['created_at' => now()->subDays(38)]);
        $recent = RecipeImport::factory()->create(['created_at' => now()->subDays(8),
            'processing_lease_until' => now()->addHour()]);
        app(RestoreOperationalState::class)->invalidate();
        $this->assertModelExists($finished);
        $this->assertNull($finished->fresh()->source_bytes);
        $this->assertSame(1, $finished->fresh()->manual_retry_count);
        $this->assertNotNull($finished->fresh()->source_text);
        $this->assertModelExists($draft);
        $this->assertEquals(now()->subDays(31), $abandoned->fresh()->failed_at);
        $this->assertNotNull($abandoned->fresh()->source_text);
        $this->assertEquals(now()->subDay(), $recent->fresh()->failed_at);
        $this->assertNull($recent->fresh()->processing_lease_until);
        app(RestoreOperationalState::class)->invalidate();
        $this->assertEquals(now()->subDay(), $recent->fresh()->failed_at);
    }

    public function test_restore_cannot_release_metadata_without_a_proven_terminal_clock(): void
    {
        config(['erasure.privacy_operator' => 'synthetic-restore-operator', 'erasure.operator_environment' => 'testing']);
        RecipeImport::factory()->failed()->create(['failed_at' => null]);
        $this->expectException(JournalUnavailable::class);
        app(RestoreOperationalState::class)->invalidate();
    }

    public function test_old_sessions_downloads_and_jobs_are_not_reactivated_and_private_review_text_survives(): void
    {
        config(['erasure.privacy_operator' => 'synthetic-restore-operator', 'erasure.operator_environment' => 'testing']);
        Storage::fake('local');
        $owner = User::factory()->create(['remember_token' => 'synthetic-old-remember-token']);
        DB::table('sessions')->insert(['id' => 'synthetic-old-session', 'user_id' => $owner->id, 'payload' => 'synthetic', 'last_activity' => now()->timestamp]);
        DB::table('password_reset_tokens')->insert(['email' => $owner->email, 'token' => 'synthetic-old-reset', 'created_at' => now()]);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
        $pending = RecipeImport::factory()->create(['user_id' => $owner->id, 'source_text' => 'Synthetic transient source']);
        $finished = RecipeImport::factory()->withDraft()->create(['user_id' => $owner->id]);
        $originalOutcome = $finished->getRawOriginal('completed_at');
        $draft = $finished->recipe_id;
        $export = new AccountExport;
        $export->forceFill(['user_id' => $owner->id, 'active_key' => hash('sha256', 'synthetic-download'),
            'state' => 'ready', 'disk' => 'local', 'expires_at' => now()->addDays(7), 'correlation_id' => (string) Str::ulid()])->save();
        Storage::disk('local')->put($export->storageKey(), 'synthetic archive');
        app(RestoreOperationalState::class)->invalidate();
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'remember_token' => null]);
        foreach (['sessions', 'password_reset_tokens', 'jobs', 'failed_jobs', 'cache_locks'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseHas('recipe_imports', ['id' => $pending->id, 'source_text' => 'Synthetic transient source']);
        $this->assertSame('cancelled', $pending->fresh()->status->value);
        $this->assertSame($originalOutcome, $finished->fresh()->getRawOriginal('completed_at'));
        $this->assertDatabaseHas('recipes', ['id' => $draft, 'user_id' => $owner->id]);
        $this->assertSame('deleted', $export->fresh()->state);
        $this->assertNull($export->fresh()->active_key);
        $this->assertFalse(Storage::disk('local')->exists($export->storageKey()));
        app(RestoreOperationalState::class)->invalidate();
        $this->assertSame($originalOutcome, $finished->fresh()->getRawOriginal('completed_at'));
    }
}
