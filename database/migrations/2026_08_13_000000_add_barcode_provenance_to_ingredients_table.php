<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->enum('barcode_provenance', [
                'manual',
                'machine_imported',
                'legacy_unknown',
            ])
                ->default('manual')
                ->after('barcode')
                ->index();
            $table->string('barcode_source', 32)
                ->nullable()
                ->after('barcode_provenance');
            $table->timestamp('barcode_imported_at')
                ->nullable()
                ->after('barcode_source');
        });

        DB::table('ingredients')
            ->whereNotNull('barcode')
            ->whereRaw("TRIM(barcode) <> ''")
            ->update([
                'barcode_provenance' => 'legacy_unknown',
                'barcode_source' => null,
                'barcode_imported_at' => null,
            ]);
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropIndex(['barcode_provenance']);
            $table->dropColumn([
                'barcode_provenance',
                'barcode_source',
                'barcode_imported_at',
            ]);
        });
    }
};
