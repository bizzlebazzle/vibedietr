<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_instruction_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['recipe_id', 'position']);
        });

        Schema::create('recipe_instruction_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('recipe_instruction_sections')->nullOnDelete();
            $table->text('text');
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['recipe_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_instruction_steps');
        Schema::dropIfExists('recipe_instruction_sections');
    }
};
