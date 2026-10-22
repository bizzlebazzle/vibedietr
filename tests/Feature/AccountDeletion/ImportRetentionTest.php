<?php

namespace Tests\Feature\AccountDeletion;

use App\Models\RecipeImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sources_expire_on_original_terminal_clock_including_pasted_text(): void
    {
        $this->freezeTime();
        $due = RecipeImport::factory()->reviewReady()->create(['completed_at' => now()->subHours(24)]);
        $fresh = RecipeImport::factory()->reviewReady()->create(['completed_at' => now()->subHours(24)->addSecond()]);
        $due->touch();

        $this->artisan('recipe-imports:cleanup-transient')->assertSuccessful();
        $this->assertNull($due->fresh()->source_text);
        $this->assertNotNull($fresh->fresh()->source_text);
        $this->artisan('recipe-imports:cleanup-transient')->assertSuccessful();
        $this->assertEquals($due->completed_at, $due->fresh()->completed_at);
    }

    public function test_metadata_expires_at_thirty_days_without_deleting_durable_draft(): void
    {
        $this->freezeTime();
        $due = RecipeImport::factory()->withDraft()->create(['completed_at' => now()->subDays(30)]);
        $fresh = RecipeImport::factory()->failed()->create(['failed_at' => now()->subDays(30)->addSecond()]);
        $recipe = $due->recipe;

        $this->artisan('recipe-imports:cleanup-transient')->assertSuccessful();
        $this->assertModelMissing($due);
        $this->assertModelExists($recipe);
        $this->assertModelExists($fresh);
        $this->assertNull($fresh->fresh()->source_text);
    }

    public function test_abandoned_expiry_keeps_original_deadline_even_when_maintenance_is_late(): void
    {
        $this->freezeTime();
        $due = RecipeImport::factory()->create(['created_at' => now()->subDays(38)]);
        $recent = RecipeImport::factory()->create(['created_at' => now()->subDays(8)]);
        $leased = RecipeImport::factory()->create(['created_at' => now()->subDays(38), 'processing_lease_until' => now()->addMinute()]);

        $this->artisan('recipe-imports:cleanup-transient')->assertSuccessful();
        $this->assertModelMissing($due);
        $this->assertEquals(now()->subDay()->startOfSecond(), $recent->fresh()->failed_at);
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
        Storage::disk('local')->put('transient-inputs/synthetic.txt', 'synthetic private source');
        $import = RecipeImport::factory()->failed()->create([
            'failed_at' => now()->subDays(2), 'cleanup_completed_at' => now()->subDay(),
            'source_disk' => 'local', 'source_key' => 'transient-inputs/synthetic.txt',
            'source_mime' => 'text/plain', 'source_bytes' => 24,
        ]);

        $this->artisan('recipe-imports:cleanup-transient')->assertSuccessful();
        Storage::disk('local')->assertMissing('transient-inputs/synthetic.txt');
        $this->assertNull($import->fresh()->source_text);
    }
}
