<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalogue_items', function (Blueprint $table) {
            $table->id();
            $table->enum('origin', ['manual', 'barcode']);
            $table->string('barcode', 64)->nullable()->unique();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('source', ['manual', 'openfoodfacts']);
            $table->string('source_identifier')->nullable();
            $table->timestamp('introduced_at');
            $table->enum('status', ['pending', 'approved', 'rejected'])->index();
            $table->timestamps();
            $table->index(['source', 'source_identifier']);
        });

        Schema::create('catalogue_item_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('catalogue_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->timestamps();
            $table->unique(['catalogue_item_id', 'version_number']);
        });

        Schema::table('catalogue_items', function (Blueprint $table) {
            $table->foreignUlid('current_catalogue_item_version_id')
                ->nullable()
                ->after('status')
                ->constrained('catalogue_item_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogue_items');
        Schema::dropIfExists('catalogue_item_versions');
    }
};
