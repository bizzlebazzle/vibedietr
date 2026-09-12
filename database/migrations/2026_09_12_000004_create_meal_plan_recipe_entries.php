<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_plan_recipe_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_plan_slot_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('recipe_id');
            $table->char('recipe_version_id', 26);
            $table->unsignedInteger('recipe_version_number');
            $table->decimal('planned_servings', 10, 2)->unsigned();
            $table->json('recipe_snapshot');
            $table->json('nutrition_snapshot');
            $table->timestamps();

            $table->index('recipe_id');
            $table->index('recipe_version_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE meal_plan_recipe_entries
            ADD CONSTRAINT meal_plan_recipe_entries_servings_check CHECK (planned_servings > 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_plan_recipe_entries');
    }
};
