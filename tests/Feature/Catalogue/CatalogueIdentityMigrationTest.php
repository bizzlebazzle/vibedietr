<?php

namespace Tests\Feature\Catalogue;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CatalogueIdentityMigrationTest extends TestCase
{
    public function test_expand_migration_and_rollback_leave_populated_ingredients_unchanged(): void
    {
        $originalConnection = DB::getDefaultConnection();

        config()->set('database.connections.nut01_migration', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('nut01_migration');
        DB::setDefaultConnection('nut01_migration');

        try {
            $this->createLegacySchema();
            $this->insertRepresentativeIngredients();

            $columnsBefore = Schema::getColumnListing('ingredients');
            $rowsBefore = $this->ingredientSnapshot();

            $migration = require database_path(
                'migrations/2026_08_29_000000_create_catalogue_identity_tables.php'
            );
            $migration->up();

            $this->assertSame($columnsBefore, Schema::getColumnListing('ingredients'));
            $this->assertSame($rowsBefore, $this->ingredientSnapshot());
            $this->assertSame(2, DB::table('ingredients')->count());
            $this->assertSame(0, DB::table('catalogue_items')->count());
            $this->assertSame(0, DB::table('catalogue_item_versions')->count());

            $migration->down();

            $this->assertFalse(Schema::hasTable('catalogue_items'));
            $this->assertFalse(Schema::hasTable('catalogue_item_versions'));
            $this->assertSame($columnsBefore, Schema::getColumnListing('ingredients'));
            $this->assertSame($rowsBefore, $this->ingredientSnapshot());
        } finally {
            Schema::dropIfExists('ingredients');
            Schema::dropIfExists('users');
            DB::setDefaultConnection($originalConnection);
            DB::purge('nut01_migration');
        }
    }

    private function createLegacySchema(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
        });

        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('barcode', 64)->nullable()->index();
            $table->enum('barcode_provenance', ['manual', 'machine_imported', 'legacy_unknown'])
                ->default('manual')
                ->index();
            $table->string('barcode_source', 32)->nullable();
            $table->timestamp('barcode_imported_at')->nullable();
            $table->json('keywords')->nullable();
            $table->json('categories')->nullable();
            $table->json('nutriments')->nullable();
            $table->decimal('quantity', 10, 3)->default(0);
            $table->string('quantity_unit', 32);
            $table->decimal('serving_quantity', 10, 3)->nullable();
            $table->string('serving_quantity_unit', 32)->nullable();
            $table->decimal('recommended_servings', 10, 2)->nullable();
            $table->string('image_url')->nullable();
            $table->timestamps();
        });
    }

    private function insertRepresentativeIngredients(): void
    {
        DB::table('users')->insert([['id' => 11], ['id' => 12]]);

        DB::table('ingredients')->insert([
            [
                'id' => 101,
                'user_id' => 11,
                'name' => 'Hand entered saffron',
                'barcode' => null,
                'barcode_provenance' => 'manual',
                'barcode_source' => null,
                'barcode_imported_at' => null,
                'keywords' => json_encode(['spice', 'rare']),
                'categories' => null,
                'nutriments' => json_encode(['per_100g' => ['energy_kcal' => 0, 'salt' => 0.0025]]),
                'quantity' => 2.500,
                'quantity_unit' => 'pinch',
                'serving_quantity' => 0.125,
                'serving_quantity_unit' => 'g',
                'recommended_servings' => 20.00,
                'image_url' => null,
                'created_at' => '2026-08-01 10:11:12',
                'updated_at' => '2026-08-02 13:14:15',
            ],
            [
                'id' => 102,
                'user_id' => 12,
                'name' => 'Imported oat drink',
                'barcode' => '0012345678905',
                'barcode_provenance' => 'machine_imported',
                'barcode_source' => 'openfoodfacts',
                'barcode_imported_at' => '2026-08-03 14:15:16',
                'keywords' => json_encode(['oats', 'drink']),
                'categories' => json_encode(['en:plant-based-beverages']),
                'nutriments' => json_encode([
                    'per_100g' => ['energy_kcal' => 46, 'fat' => 1.5, 'sugars' => 3.2],
                    'per_serving' => ['energy_kcal' => 115, 'fat' => 3.75, 'sugars' => 8],
                ]),
                'quantity' => 1000.000,
                'quantity_unit' => 'ml',
                'serving_quantity' => 250.000,
                'serving_quantity_unit' => 'ml',
                'recommended_servings' => 4.00,
                'image_url' => 'https://example.test/oat-drink.jpg',
                'created_at' => '2026-08-03 14:15:16',
                'updated_at' => '2026-08-04 17:18:19',
            ],
        ]);
    }

    private function ingredientSnapshot(): string
    {
        return (string) json_encode(
            DB::table('ingredients')->orderBy('id')->get()->map(fn (object $row) => (array) $row)->all(),
            JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }
}
