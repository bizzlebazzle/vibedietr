<?php

namespace Tests\Feature\Catalogue;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CatalogueImportedProductDataMigrationTest extends TestCase
{
    public function test_additive_migration_and_rollback_preserve_existing_catalogue_and_ingredient_data(): void
    {
        $originalConnection = DB::getDefaultConnection();

        config()->set('database.connections.nut06_migration', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('nut06_migration');
        DB::setDefaultConnection('nut06_migration');

        try {
            $this->createExistingSchema();
            $this->insertExistingRows();
            $catalogueBefore = DB::table('catalogue_items')->get()->toJson();
            $ingredientBefore = DB::table('ingredients')->get()->toJson();

            $migration = require database_path(
                'migrations/2026_09_01_000002_add_imported_product_data_to_catalogue_item_versions.php'
            );
            $migration->up();

            $version = (array) DB::table('catalogue_item_versions')->sole();
            foreach ([
                'name',
                'keywords',
                'categories',
                'image_url',
                'name_source',
                'keywords_source',
                'categories_source',
                'package_source',
                'serving_source',
                'image_source',
            ] as $column) {
                $this->assertArrayHasKey($column, $version);
                $this->assertNull($version[$column]);
            }
            $this->assertSame('400', (string) $version['amount_per_item']);
            $this->assertSame($catalogueBefore, DB::table('catalogue_items')->get()->toJson());
            $this->assertSame($ingredientBefore, DB::table('ingredients')->get()->toJson());

            $migration->down();

            $this->assertFalse(Schema::hasColumn('catalogue_item_versions', 'name'));
            $this->assertTrue(Schema::hasColumn('catalogue_item_versions', 'amount_per_item'));
            $this->assertSame('400', (string) DB::table('catalogue_item_versions')->value('amount_per_item'));
            $this->assertSame($catalogueBefore, DB::table('catalogue_items')->get()->toJson());
            $this->assertSame($ingredientBefore, DB::table('ingredients')->get()->toJson());
        } finally {
            Schema::dropIfExists('catalogue_item_versions');
            Schema::dropIfExists('catalogue_items');
            Schema::dropIfExists('ingredients');
            DB::setDefaultConnection($originalConnection);
            DB::purge('nut06_migration');
        }
    }

    private function createExistingSchema(): void
    {
        Schema::create('catalogue_items', function (Blueprint $table) {
            $table->id();
            $table->string('barcode')->nullable()->unique();
            $table->timestamps();
        });
        Schema::create('catalogue_item_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('catalogue_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->unsignedInteger('package_count')->nullable();
            $table->string('item_type', 64)->nullable();
            $table->decimal('amount_per_item', 36, 18)->nullable();
            $table->string('amount_per_item_unit', 32)->nullable();
            $table->decimal('servings_per_item', 36, 18)->nullable();
            $table->decimal('serving_amount', 36, 18)->nullable();
            $table->string('serving_amount_unit', 32)->nullable();
            $table->string('serving_amount_basis', 32)->nullable();
            $table->timestamps();
        });
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
    }

    private function insertExistingRows(): void
    {
        DB::table('catalogue_items')->insert([
            'id' => 1,
            'barcode' => '0012345678905',
            'created_at' => '2026-09-01 12:00:00',
            'updated_at' => '2026-09-01 12:00:00',
        ]);
        DB::table('catalogue_item_versions')->insert([
            'id' => '01K3ZPBQ4DKW1QR2TTQTX3QRAA',
            'catalogue_item_id' => 1,
            'version_number' => 1,
            'package_count' => 1,
            'amount_per_item' => '400',
            'amount_per_item_unit' => 'g',
            'created_at' => '2026-09-01 12:00:00',
            'updated_at' => '2026-09-01 12:00:00',
        ]);
        DB::table('ingredients')->insert(['id' => 99, 'name' => 'Existing ingredient']);
    }
}
