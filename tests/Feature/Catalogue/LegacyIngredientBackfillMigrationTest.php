<?php

namespace Tests\Feature\Catalogue;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacyIngredientBackfillMigrationTest extends TestCase
{
    public function test_unused_mapping_migration_and_rollback_leave_populated_legacy_rows_unchanged(): void
    {
        $originalConnection = DB::getDefaultConnection();

        config()->set('database.connections.nut02_migration', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('nut02_migration');
        DB::setDefaultConnection('nut02_migration');

        try {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
            });
            Schema::create('catalogue_items', function (Blueprint $table): void {
                $table->id();
            });
            Schema::create('ingredients', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('name');
                $table->string('barcode', 64)->nullable();
                $table->json('nutriments')->nullable();
                $table->timestamps();
            });
            DB::table('users')->insert(['id' => 41]);
            DB::table('ingredients')->insert([
                'id' => 91,
                'user_id' => 41,
                'name' => 'Untouched legacy ingredient',
                'barcode' => '0012345678905',
                'nutriments' => json_encode(['per_100g' => ['energy_kcal' => 0]]),
                'created_at' => '2026-08-01 10:11:12',
                'updated_at' => '2026-08-02 13:14:15',
            ]);
            $before = $this->legacySnapshot();

            $migration = require database_path(
                'migrations/2026_08_31_000000_create_legacy_ingredient_catalogue_mappings.php'
            );
            $migration->up();

            $this->assertTrue(Schema::hasTable('legacy_ingredient_catalogue_mappings'));
            $this->assertSame($before, $this->legacySnapshot());
            $this->assertSame(0, DB::table('legacy_ingredient_catalogue_mappings')->count());

            $migration->down();

            $this->assertFalse(Schema::hasTable('legacy_ingredient_catalogue_mappings'));
            $this->assertSame($before, $this->legacySnapshot());
        } finally {
            Schema::dropIfExists('legacy_ingredient_catalogue_mappings');
            Schema::dropIfExists('ingredients');
            Schema::dropIfExists('catalogue_items');
            Schema::dropIfExists('users');
            DB::setDefaultConnection($originalConnection);
            DB::purge('nut02_migration');
        }
    }

    private function legacySnapshot(): string
    {
        return json_encode(
            DB::table('ingredients')->orderBy('id')->get()->map(fn (object $row) => (array) $row)->all(),
            JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }
}
