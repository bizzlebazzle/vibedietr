<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_plan_item_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_plan_slot_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['catalogue', 'one_off']);
            $table->decimal('planned_amount', 38, 18)->unsigned();
            $table->string('planned_unit', 32);
            $table->unsignedBigInteger('catalogue_item_id')->nullable();
            $table->char('catalogue_item_version_id', 26)->nullable();
            $table->unsignedInteger('catalogue_item_version_number')->nullable();
            $table->json('catalogue_snapshot')->nullable();
            $table->json('catalogue_nutrition_snapshot')->nullable();
            $table->string('one_off_wording')->nullable();
            $table->json('one_off_nutrition')->nullable();
            $table->timestamps();

            $table->index('catalogue_item_id');
            $table->index('catalogue_item_version_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE meal_plan_item_entries
            ADD CONSTRAINT meal_plan_item_entries_amount_check CHECK (planned_amount > 0),
            ADD CONSTRAINT meal_plan_item_entries_payload_check CHECK (
                (
                    kind = 'catalogue'
                    AND catalogue_item_id IS NOT NULL
                    AND catalogue_item_version_id IS NOT NULL
                    AND catalogue_item_version_number IS NOT NULL
                    AND catalogue_snapshot IS NOT NULL
                    AND catalogue_nutrition_snapshot IS NOT NULL
                    AND one_off_wording IS NULL
                    AND one_off_nutrition IS NULL
                )
                OR
                (
                    kind = 'one_off'
                    AND catalogue_item_id IS NULL
                    AND catalogue_item_version_id IS NULL
                    AND catalogue_item_version_number IS NULL
                    AND catalogue_snapshot IS NULL
                    AND catalogue_nutrition_snapshot IS NULL
                    AND one_off_wording IS NOT NULL
                )
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_plan_item_entries');
    }
};
