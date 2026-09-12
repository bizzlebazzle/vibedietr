<?php

use App\Domain\MealPlans\MealPlanType;
use App\Domain\MealPlans\MealPlanVisibility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', array_column(MealPlanType::cases(), 'value'));
            $table->enum('visibility', array_column(MealPlanVisibility::cases(), 'value'))
                ->default(MealPlanVisibility::Private->value);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE meal_plans
            ADD CONSTRAINT meal_plans_type_dates_check CHECK (
                (`type` = 'reusable' AND starts_on IS NULL AND ends_on IS NULL)
                OR
                (`type` = 'dated' AND starts_on IS NOT NULL AND ends_on IS NOT NULL AND ends_on >= starts_on)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_plans');
    }
};
