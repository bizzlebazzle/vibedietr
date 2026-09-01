<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalogue_item_versions', function (Blueprint $table) {
            $table->string('name')->nullable();
            $table->json('keywords')->nullable();
            $table->json('categories')->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->enum('name_source', ['manual', 'openfoodfacts'])->nullable();
            $table->enum('keywords_source', ['manual', 'openfoodfacts'])->nullable();
            $table->enum('categories_source', ['manual', 'openfoodfacts'])->nullable();
            $table->enum('package_source', ['manual', 'openfoodfacts'])->nullable();
            $table->enum('serving_source', ['manual', 'openfoodfacts'])->nullable();
            $table->enum('image_source', ['manual', 'openfoodfacts'])->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('catalogue_item_versions', function (Blueprint $table) {
            $table->dropColumn([
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
            ]);
        });
    }
};
