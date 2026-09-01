<?php

namespace Tests\Feature\Catalogue;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CataloguePackageStructureMigrationTest extends TestCase
{
    public function test_additive_migration_leaves_existing_catalogue_and_ingredient_data_unchanged(): void
    {
        $originalConnection = DB::getDefaultConnection();

        config()->set('database.connections.nut04_migration', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('nut04_migration');
        DB::setDefaultConnection('nut04_migration');

        try {
            $this->createExistingSchema();
            $this->insertExistingRows();
            $catalogueBefore = DB::table('catalogue_items')->orderBy('id')->get()->toJson();
            $ingredientBefore = DB::table('ingredients')->orderBy('id')->get()->toJson();

            $migration = require database_path(
                'migrations/2026_09_01_000000_add_package_structure_to_catalogue_item_versions.php'
            );
            $migration->up();

            $version = (array) DB::table('catalogue_item_versions')->sole();
            $this->assertSame($catalogueBefore, DB::table('catalogue_items')->orderBy('id')->get()->toJson());
            $this->assertSame($ingredientBefore, DB::table('ingredients')->orderBy('id')->get()->toJson());

            foreach ([
                'package_count',
                'item_type',
                'amount_per_item',
                'amount_per_item_unit',
                'servings_per_item',
                'serving_amount',
                'serving_amount_unit',
                'serving_amount_basis',
            ] as $column) {
                $this->assertArrayHasKey($column, $version);
                $this->assertNull($version[$column]);
            }

            $migration->down();

            $this->assertSame(
                ['id', 'catalogue_item_id', 'version_number', 'created_at', 'updated_at'],
                Schema::getColumnListing('catalogue_item_versions'),
            );
            $this->assertSame($catalogueBefore, DB::table('catalogue_items')->orderBy('id')->get()->toJson());
            $this->assertSame($ingredientBefore, DB::table('ingredients')->orderBy('id')->get()->toJson());
        } finally {
            Schema::dropIfExists('catalogue_item_versions');
            Schema::dropIfExists('catalogue_items');
            Schema::dropIfExists('ingredients');
            DB::setDefaultConnection($originalConnection);
            DB::purge('nut04_migration');
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
            $table->timestamps();
        });

        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->string('barcode')->nullable();
            $table->decimal('quantity', 10, 3)->default(0);
            $table->string('quantity_unit', 32);
        });
    }

    private function insertExistingRows(): void
    {
        DB::table('catalogue_items')->insert([
            'id' => 1,
            'barcode' => '0012345678905',
            'created_at' => '2026-08-31 12:00:00',
            'updated_at' => '2026-08-31 12:00:00',
        ]);
        DB::table('catalogue_item_versions')->insert([
            'id' => '01K3ZPBQ4DKW1QR2TTQTX3QRAA',
            'catalogue_item_id' => 1,
            'version_number' => 1,
            'created_at' => '2026-08-31 12:00:00',
            'updated_at' => '2026-08-31 12:00:00',
        ]);
        DB::table('ingredients')->insert([
            'id' => 99,
            'barcode' => '0012345678905',
            'quantity' => '0',
            'quantity_unit' => 'can',
        ]);
    }
}
