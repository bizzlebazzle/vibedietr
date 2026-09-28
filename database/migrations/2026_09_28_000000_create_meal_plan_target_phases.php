<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_plan_target_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('nutrition_target_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('profile_name_snapshot');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->timestamps();

            $table->index(['meal_plan_id', 'starts_on', 'ends_on']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE meal_plan_target_phases
            ADD CONSTRAINT target_phase_dates_check CHECK (ends_on IS NULL OR ends_on >= starts_on)
        SQL);

        Schema::create('meal_plan_target_phase_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_plan_target_phase_id')->constrained()->cascadeOnDelete();
            $table->date('effective_on');
            $table->json('targets');
            $table->timestamps();

            $table->unique(['meal_plan_target_phase_id', 'effective_on'], 'target_phase_values_effective_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_plan_target_phase_values');
        Schema::dropIfExists('meal_plan_target_phases');
    }
};
