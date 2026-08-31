<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_ingredient_catalogue_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ingredient_id')->unique();
            $table->foreignId('legacy_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('catalogue_item_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->enum('classification', [
                'legacy_manual',
                'verified_imported',
                'ambiguous_barcode',
                'duplicate',
            ])->index();
            $table->enum('review_reason', [
                'malformed_barcode',
                'unverified_legacy_barcode',
                'missing_barcode',
                'missing_import_source',
                'missing_import_timestamp',
                'conflicting_import_provenance',
                'duplicate_barcode',
            ])->nullable()->index();
            $table->json('legacy_snapshot');
            $table->char('legacy_checksum', 64);
            $table->unsignedSmallInteger('backfill_version');
            $table->timestamp('backfilled_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_ingredient_catalogue_mappings');
    }
};
