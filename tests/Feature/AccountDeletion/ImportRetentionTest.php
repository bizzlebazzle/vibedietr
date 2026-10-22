<?php

namespace Tests\Feature\AccountDeletion;

use App\Models\RecipeImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_transient_files_expire_on_original_outcome_while_private_review_text_survives(): void
    {
        $this->freezeSecond();
        Storage::fake('local');
        Storage::disk('local')->put('inputs/due', 'synthetic bytes');
        Storage::disk('local')->put('inputs/fresh', 'synthetic bytes');
        $due = RecipeImport::factory()->reviewReady()->create([
            'completed_at' => now()->subHours(24), 'source_disk' => 'local', 'source_key' => 'inputs/due',
        ]);
        $fresh = RecipeImport::factory()->reviewReady()->create([
            'completed_at' => now()->subHours(24)->addSecond(), 'source_disk' => 'local', 'source_key' => 'inputs/fresh',
        ]);
        $originalText = $due->source_text;
        $due->touch();

        $this->artisan('recipe-imports:cleanup-transient')->assertSuccessful();
        Storage::disk('local')->assertMissing('inputs/due');
        Storage::disk('local')->assertExists('inputs/fresh');
        $this->assertSame($originalText, $due->fresh()->source_text);
        $this->artisan('recipe-imports:cleanup-transient')->assertSuccessful();
        $this->assertEquals($due->completed_at, $due->fresh()->completed_at);
    }

    public function test_thirty_day_operational_minimization_preserves_private_provenance_and_draft(): void
    {
        $this->freezeSecond();
        $due = RecipeImport::factory()->withDraft()->create([
            'completed_at' => now()->subDays(30), 'source_bytes' => 42, 'source_extension' => 'txt',
            'canonical_mime' => 'image/png', 'cleanup_completed_at' => now()->subDays(29), 'manual_retry_count' => 1,
        ]);
        $fresh = RecipeImport::factory()->failed()->create([
            'failed_at' => now()->subDays(30)->addSecond(), 'source_bytes' => 37, 'manual_retry_count' => 1,
        ]);
        $recipe = $due->recipe;
        $text = $due->source_text;
        $provenance = $due->provenance;
        $identity = $due->idempotency_key;

        $this->artisan('recipe-imports:cleanup-transient')->assertSuccessful();
        $this->assertModelExists($due);
        $this->assertModelExists($recipe);
        $this->assertSame($text, $due->fresh()->source_text);
        $this->assertEquals($provenance, $due->fresh()->provenance);
        $this->assertSame($identity, $due->fresh()->idempotency_key);
        $this->assertNull($due->fresh()->source_bytes);
        $this->assertNull($due->fresh()->source_extension);
        $this->assertNull($due->fresh()->cleanup_completed_at);
        $this->assertSame(1, $due->fresh()->manual_retry_count);
        $this->assertSame(37, $fresh->fresh()->source_bytes);
        $this->assertSame(1, $fresh->fresh()->manual_retry_count);
    }

    public function test_late_abandoned_cleanup_does_not_restart_original_processing_deadline(): void
    {
        $this->freezeSecond();
        $due = RecipeImport::factory()->create(['created_at' => now()->subDays(38), 'source_bytes' => 42]);
        $recent = RecipeImport::factory()->create(['created_at' => now()->subDays(8)]);
        $leased = RecipeImport::factory()->create(['created_at' => now()->subDays(38), 'processing_lease_until' => now()->addMinute()]);
        $text = $due->source_text;

        $this->artisan('recipe-imports:cleanup-transient')->assertSuccessful();
        $this->assertEquals(now()->subDays(31), $due->fresh()->failed_at);
        $this->assertNull($due->fresh()->source_bytes);
        $this->assertSame($text, $due->fresh()->source_text);
        $this->assertEquals(now()->subDay(), $recent->fresh()->failed_at);
        $this->assertNotNull($leased->fresh()->source_text);
    }

    public function test_missing_terminal_clock_fails_closed_without_deleting_source(): void
    {
        $import = RecipeImport::factory()->failed()->create(['failed_at' => null]);
        $this->artisan('recipe-imports:cleanup-transient')->assertFailed();
        $this->assertNotNull($import->fresh()->source_text);
    }

    public function test_restored_cleanup_marker_cannot_bypass_physical_source_verification(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('inputs/synthetic.txt', 'synthetic private source');
        $import = RecipeImport::factory()->failed()->create([
            'failed_at' => now()->subDays(2), 'cleanup_completed_at' => now()->subDay(),
            'source_disk' => 'local', 'source_key' => 'inputs/synthetic.txt',
            'source_mime' => 'text/plain', 'source_bytes' => 24,
        ]);
        $this->artisan('recipe-imports:cleanup-transient')->assertSuccessful();
        Storage::disk('local')->assertMissing('inputs/synthetic.txt');
        $this->assertNotNull($import->fresh()->source_text);
    }

    public function test_failed_physical_cleanup_is_not_reported_as_success(): void
    {
        RecipeImport::factory()->failed()->create(['failed_at' => now()->subDays(31),
            'source_disk' => 'local', 'source_key' => 'inputs/synthetic-failure']);
        Storage::shouldReceive('disk')->with('local')->andThrow(new \RuntimeException('synthetic storage failure'));
        $this->artisan('recipe-imports:cleanup-transient')->assertFailed();
    }
}
