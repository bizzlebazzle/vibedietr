<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_ingredient_line_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_ingredient_line_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUlid('catalogue_item_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('selected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('provenance', ['manually_selected_by_creator']);
            $table->enum('review_state', ['confirmed', 'needs_review']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_ingredient_line_matches');
    }
};
