<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_ingredient_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->text('original_text');
            $table->unsignedInteger('position');
            $table->decimal('quantity', 38, 18)->nullable();
            $table->string('standard_unit', 32)->nullable();
            $table->string('custom_unit', 32)->nullable();
            $table->string('generic_wording')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['recipe_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_ingredient_lines');
    }
};
