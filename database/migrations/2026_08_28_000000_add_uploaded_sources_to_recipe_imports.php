<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipe_imports', function (Blueprint $table) {
            $table->string('source_disk', 64)->nullable()->after('source_text');
            $table->string('source_key', 160)->nullable()->after('source_disk');
            $table->string('source_mime', 100)->nullable()->after('source_key');
            $table->unsignedBigInteger('source_bytes')->nullable()->after('source_mime');
            $table->string('source_extension', 12)->nullable()->after('source_bytes');
            $table->string('canonical_disk', 64)->nullable()->after('source_extension');
            $table->string('canonical_key', 160)->nullable()->after('canonical_disk');
            $table->string('canonical_mime', 100)->nullable()->after('canonical_key');
            $table->unsignedInteger('image_width')->nullable()->after('canonical_mime');
            $table->unsignedInteger('image_height')->nullable()->after('image_width');
            $table->timestamp('processing_lease_until')->nullable()->after('image_height');
            $table->timestamp('source_stored_at')->nullable()->after('processing_lease_until');
            $table->timestamp('cleanup_completed_at')->nullable()->after('source_stored_at');

            $table->index(['status', 'source_stored_at']);
            $table->index('processing_lease_until');
        });
    }

    public function down(): void
    {
        Schema::table('recipe_imports', function (Blueprint $table) {
            $table->dropIndex(['status', 'source_stored_at']);
            $table->dropIndex(['processing_lease_until']);
            $table->dropColumn([
                'source_disk', 'source_key', 'source_mime', 'source_bytes',
                'source_extension', 'canonical_disk', 'canonical_key',
                'canonical_mime', 'image_width', 'image_height',
                'processing_lease_until', 'source_stored_at', 'cleanup_completed_at',
            ]);
        });
    }
};
