<?php

namespace Tests\Feature\Operations;

use App\Models\Recipe;
use App\Models\User;
use App\Operations\DatabaseReconciliation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class DatabaseReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciles_every_table_without_disclosing_private_content(): void
    {
        Recipe::factory()->finalizedPrivate()->create(['title' => 'Synthetic private content']);
        $check = new DatabaseReconciliation;
        $manifest = $check->capture(DB::connection());
        $check->verify(DB::connection(), $manifest);
        $this->assertStringNotContainsString('Synthetic private content', json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->assertSame(1, $manifest['tables']['recipes']['data']['count']);
        $this->assertNotEmpty($manifest['foreign_keys']);
    }

    public function test_detects_content_changes_even_when_counts_are_unchanged(): void
    {
        $user = User::factory()->create();
        $check = new DatabaseReconciliation;
        $baseline = $check->capture(DB::connection());
        $user->forceFill(['name' => 'Changed'])->save();
        $this->expectExceptionMessage('recovery_reconciliation_mismatch');
        $check->verify(DB::connection(), $baseline);
    }

    public function test_detects_orphans_created_by_an_import_with_constraints_disabled(): void
    {
        $recipe = Recipe::factory()->create();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::table('recipes')->where('id', $recipe->id)->update(['user_id' => 999999999]);
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
        $this->expectExceptionMessage('recovery_orphaned_reference');
        (new DatabaseReconciliation)->capture(DB::connection());
    }

    public function test_rejects_unknown_migrations_and_changed_migration_code(): void
    {
        $check = new DatabaseReconciliation;
        $baseline = $check->capture(DB::connection());
        $baseline['migration_files'][$baseline['applied'][0]] = str_repeat('0', 64);
        try {
            $check->verify(DB::connection(), $baseline, true);
            $this->fail('Changed migration code must fail');
        } catch (RuntimeException $error) {
            $this->assertSame('recovery_migration_history_changed', $error->getMessage());
        }
        DB::table('migrations')->insert(['migration' => 'unknown_release', 'batch' => 99]);
        $this->expectExceptionMessage('recovery_unknown_migration');
        $check->capture(DB::connection());
    }

    public function test_additive_postflight_preserves_existing_fields_and_rejects_row_loss(): void
    {
        $user = User::factory()->create();
        $check = new DatabaseReconciliation;
        $baseline = $check->capture(DB::connection());
        Schema::table('users', fn (Blueprint $table) => $table->string('recovery_probe')->nullable());
        try {
            $check->verify(DB::connection(), $baseline, true);
            DB::table('users')->where('id', $user->id)->delete();
            $this->expectExceptionMessage('recovery_existing_data_changed');
            $check->verify(DB::connection(), $baseline, true);
        } finally {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('recovery_probe'));
        }
    }

    public function test_command_requires_freeze_and_never_overwrites_a_baseline(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'reconciliation-');
        try {
            file_put_contents($path, 'Existing evidence');
            $this->artisan('operations:reconcile-database', ['--write' => $path])->assertFailed();
            $this->artisan('operations:reconcile-database', ['--write' => $path, '--quiesced' => true])->assertFailed();
            $this->assertSame('Existing evidence', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }
}
