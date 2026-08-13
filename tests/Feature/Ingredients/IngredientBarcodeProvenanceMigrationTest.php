<?php

namespace Tests\Feature\Ingredients;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IngredientBarcodeProvenanceMigrationTest extends TestCase
{
    public function test_additive_migration_classifies_legacy_rows_without_changing_their_data(): void
    {
        $originalConnection = DB::getDefaultConnection();

        config()->set('database.connections.stb08_migration', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('stb08_migration');
        DB::setDefaultConnection('stb08_migration');

        try {
            Schema::create('ingredients', function (Blueprint $table) {
                $table->id();
                $table->string('barcode', 64)->nullable();
                $table->json('nutriments')->nullable();
            });

            DB::table('ingredients')->insert([
                ['id' => 1, 'barcode' => null, 'nutriments' => null],
                ['id' => 2, 'barcode' => '', 'nutriments' => null],
                [
                    'id' => 3,
                    'barcode' => '0012345678905',
                    'nutriments' => json_encode(['per_100g' => ['energy_kcal' => 123]]),
                ],
            ]);

            $migration = require database_path(
                'migrations/2026_08_13_000000_add_barcode_provenance_to_ingredients_table.php'
            );
            $migration->up();

            $manual = DB::table('ingredients')->where('id', 1)->first();
            $blank = DB::table('ingredients')->where('id', 2)->first();
            $legacy = DB::table('ingredients')->where('id', 3)->first();

            $this->assertSame('manual', $manual->barcode_provenance);
            $this->assertSame('manual', $blank->barcode_provenance);
            $this->assertSame('legacy_unknown', $legacy->barcode_provenance);
            $this->assertNull($legacy->barcode_source);
            $this->assertNull($legacy->barcode_imported_at);
            $this->assertSame('0012345678905', $legacy->barcode);
            $this->assertSame(
                ['per_100g' => ['energy_kcal' => 123]],
                json_decode($legacy->nutriments, true),
            );

            $migration->down();

            $this->assertFalse(Schema::hasColumn('ingredients', 'barcode_provenance'));
            $this->assertSame('0012345678905', DB::table('ingredients')->where('id', 3)->value('barcode'));
        } finally {
            Schema::dropIfExists('ingredients');
            DB::setDefaultConnection($originalConnection);
            DB::purge('stb08_migration');
        }
    }
}
