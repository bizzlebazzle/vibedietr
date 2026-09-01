<?php

namespace Tests\Feature\Catalogue;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CatalogueNutritionMigrationTest extends TestCase
{
    public function test_additive_migration_preserves_existing_catalogue_versions_and_ingredients(): void
    {
        $originalConnection = DB::getDefaultConnection();

        config()->set('database.connections.nut05_migration', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('nut05_migration');
        DB::setDefaultConnection('nut05_migration');

        try {
            Schema::create('catalogue_items', function (Blueprint $table) {
                $table->id();
                $table->string('barcode')->nullable();
                $table->timestamps();
            });
            Schema::create('catalogue_item_versions', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->foreignId('catalogue_item_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('version_number');
                $table->timestamps();
            });
            Schema::create('ingredients', function (Blueprint $table) {
                $table->id();
                $table->json('nutriments')->nullable();
            });

            DB::table('catalogue_items')->insert([
                'id' => 1,
                'barcode' => '0012345678905',
                'created_at' => '2026-09-01 10:00:00',
                'updated_at' => '2026-09-01 10:00:00',
            ]);
            DB::table('catalogue_item_versions')->insert([
                'id' => '01K3ZPBQ4DKW1QR2TTQTX3QRAA',
                'catalogue_item_id' => 1,
                'version_number' => 1,
                'created_at' => '2026-09-01 10:00:00',
                'updated_at' => '2026-09-01 10:00:00',
            ]);
            DB::table('ingredients')->insert([
                'id' => 99,
                'nutriments' => json_encode([
                    'per_100g' => ['energy_kcal' => 0, 'protein' => '7.25'],
                ], JSON_THROW_ON_ERROR),
            ]);

            $catalogueBefore = DB::table('catalogue_items')->get()->toJson();
            $versionsBefore = DB::table('catalogue_item_versions')->get()->toJson();
            $ingredientsBefore = DB::table('ingredients')->get()->toJson();

            $migration = require database_path(
                'migrations/2026_09_01_000001_create_catalogue_nutrition_tables.php'
            );
            $migration->up();

            $this->assertTrue(Schema::hasTable('catalogue_nutrient_observations'));
            $this->assertTrue(Schema::hasTable('catalogue_nutrient_values'));
            $this->assertSame($catalogueBefore, DB::table('catalogue_items')->get()->toJson());
            $this->assertSame($versionsBefore, DB::table('catalogue_item_versions')->get()->toJson());
            $this->assertSame($ingredientsBefore, DB::table('ingredients')->get()->toJson());
            $this->assertDatabaseCount('catalogue_nutrient_observations', 0);
            $this->assertDatabaseCount('catalogue_nutrient_values', 0);

            $migration->down();

            $this->assertFalse(Schema::hasTable('catalogue_nutrient_values'));
            $this->assertFalse(Schema::hasTable('catalogue_nutrient_observations'));
            $this->assertSame($catalogueBefore, DB::table('catalogue_items')->get()->toJson());
            $this->assertSame($versionsBefore, DB::table('catalogue_item_versions')->get()->toJson());
            $this->assertSame($ingredientsBefore, DB::table('ingredients')->get()->toJson());
        } finally {
            Schema::dropIfExists('catalogue_nutrient_values');
            Schema::dropIfExists('catalogue_nutrient_observations');
            Schema::dropIfExists('catalogue_item_versions');
            Schema::dropIfExists('catalogue_items');
            Schema::dropIfExists('ingredients');
            DB::setDefaultConnection($originalConnection);
            DB::purge('nut05_migration');
        }
    }
}
