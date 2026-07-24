<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();

            // 1) User who added the ingredient
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // 2) Name
            $table->string('name');

            // 3) Barcode (nullable; may come from scanner)
            $table->string('barcode', 64)->nullable()->index();

            // 4) Keywords (nullable; from OFF)
            $table->json('keywords')->nullable();

            // 5) Categories (nullable; from OFF)
            $table->json('categories')->nullable();

            // 6) Nutriments (nullable; store as JSON for flexibility)
            // Suggested structure:
            // {
            //   "raw": {...},
            //   "per_100g": {...},
            //   "per_serving": {...}
            // }
            $table->json('nutriments')->nullable();

            // 7) Quantity (how much the item contains, e.g., 500)
            $table->decimal('quantity', 10, 3)->default(0);

            // 8) Quantity unit (e.g., g, ml, tsp)
            $table->string('quantity_unit', 32);

            // 9) Serving quantity (nullable)
            $table->decimal('serving_quantity', 10, 3)->nullable();

            // 10) Serving quantity unit (nullable)
            $table->string('serving_quantity_unit', 32)->nullable();

            // 11) Recommended servings (nullable)
            $table->decimal('recommended_servings', 10, 2)->nullable();

            // 12) Product image URL (nullable; OFF image or null if manual)
            $table->string('image_url')->nullable();

            // 13) Timestamps
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
