<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('normalized_name', 100);
            $table->timestamps();
            $table->unique(['user_id', 'normalized_name']);
        });
        Schema::create('private_recipe_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('normalized_name', 100);
            $table->timestamps();
            $table->unique(['user_id', 'normalized_name']);
        });
        Schema::create('recipe_collection_recipes', function (Blueprint $table) {
            $table->foreignId('recipe_collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['recipe_collection_id', 'recipe_id']);
        });
        Schema::create('recipe_collection_bookmarks', function (Blueprint $table) {
            $table->foreignId('recipe_collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bookmark_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['recipe_collection_id', 'bookmark_id']);
        });
        Schema::create('private_recipe_tag_recipes', function (Blueprint $table) {
            $table->foreignId('private_recipe_tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['private_recipe_tag_id', 'recipe_id']);
        });
        Schema::create('private_recipe_tag_bookmarks', function (Blueprint $table) {
            $table->foreignId('private_recipe_tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bookmark_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['private_recipe_tag_id', 'bookmark_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('private_recipe_tag_bookmarks');
        Schema::dropIfExists('private_recipe_tag_recipes');
        Schema::dropIfExists('recipe_collection_bookmarks');
        Schema::dropIfExists('recipe_collection_recipes');
        Schema::dropIfExists('private_recipe_tags');
        Schema::dropIfExists('recipe_collections');
    }
};
