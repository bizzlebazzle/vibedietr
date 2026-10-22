<?php

namespace Tests\Feature\AccountDeletion;

use App\Models\RecipeImport;
use App\Operations\DatabaseReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class RecoveryInputProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_capture_excludes_source_without_mutating_live_input_and_target_checks_remain_exact(): void
    {
        $import = RecipeImport::factory()->withDraft()->create(['source_text' => "Synthetic secret ' source \\ bytes"]);
        $original = $import->source_text;
        $path = sys_get_temp_dir().'/dep08-projection-'.bin2hex(random_bytes(12)).'.sql';
        try {
            $reconciliation = app(DatabaseReconciliation::class);
            $baseline = $reconciliation->capture(DB::connection(), true);
            $this->artisan('operations:recovery-input-projection', ['--quiesced' => true, '--write' => $path])->assertSuccessful();
            $sql = file_get_contents($path);
            $this->assertStringNotContainsString('Synthetic secret', $sql);
            $this->assertSame($original, $import->fresh()->source_text);
            $this->assertSame(0600, fileperms($path) & 0777);
            try {
                $reconciliation->verify(DB::connection(), $baseline);
                $this->fail('Raw source text was accepted on a restored target.');
            } catch (RuntimeException $exception) {
                $this->assertSame('recovery_reconciliation_mismatch', $exception->getMessage());
            }
            DB::table('recipe_imports')->where('id', $import->id)->delete();
            DB::unprepared($sql);
            $this->assertNull($import->fresh()->source_text);
            $this->assertModelExists($import->recipe);
            $reconciliation->verify(DB::connection(), $baseline);
            $this->artisan('operations:recovery-input-projection', ['--quiesced' => true, '--write' => $path])->assertFailed();
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_capture_requires_frozen_writers_and_restricted_external_staging(): void
    {
        $this->artisan('operations:recovery-input-projection', ['--write' => '/tmp/unused-dep08.sql'])->assertFailed();
        $this->artisan('operations:recovery-input-projection', ['--quiesced' => true, '--write' => storage_path('app/unsafe-dep08.sql')])->assertFailed();
        $this->assertFileDoesNotExist(storage_path('app/unsafe-dep08.sql'));
    }
}
