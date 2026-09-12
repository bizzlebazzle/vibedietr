<?php

use App\Domain\MealPlans\PlanSlotKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_plan_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('day_index')->nullable();
            $table->date('date')->nullable();
            $table->timestamps();

            $table->unique(['meal_plan_id', 'day_index']);
            $table->unique(['meal_plan_id', 'date']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE meal_plan_days
            ADD CONSTRAINT meal_plan_days_identity_check CHECK (
                (day_index IS NOT NULL AND `date` IS NULL)
                OR
                (day_index IS NULL AND `date` IS NOT NULL)
            )
        SQL);

        Schema::create('meal_plan_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_plan_day_id')->constrained()->cascadeOnDelete();
            $table->enum('standard_key', array_column(PlanSlotKey::cases(), 'value'))->nullable();
            $table->string('name');
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['meal_plan_day_id', 'standard_key']);
            $table->unique(['meal_plan_day_id', 'position']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE meal_plan_slots
            ADD CONSTRAINT meal_plan_slots_fixed_names_check CHECK (
                (standard_key = 'drinks' AND name = 'Drinks')
                OR
                (standard_key = 'snacks' AND name = 'Snacks')
                OR
                standard_key NOT IN ('drinks', 'snacks')
                OR
                standard_key IS NULL
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_plan_slots');
        Schema::dropIfExists('meal_plan_days');
    }
};
