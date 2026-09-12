<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipe_imports', function (Blueprint $table) {
            $table->json('nutrition_source')->nullable()->after('provenance');
        });

        Schema::create('recipe_nutrition_override_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('recipe_version_id')->constrained('recipe_versions')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('event', ['added', 'changed', 'removed']);
            $table->enum('prior_source', ['creator_override', 'imported_source', 'ingredient_estimate']);
            $table->enum('resulting_source', ['creator_override', 'imported_source', 'ingredient_estimate']);
            $table->json('prior_values');
            $table->json('resulting_values')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('occurred_at');
            $table->ulid('audit_event_id')->unique();
            $table->timestamps();
            $table->index(['recipe_version_id', 'occurred_at'], 'recipe_nutrition_override_version_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_nutrition_override_events');
        Schema::table('recipe_imports', function (Blueprint $table) {
            $table->dropColumn('nutrition_source');
        });
    }
};
