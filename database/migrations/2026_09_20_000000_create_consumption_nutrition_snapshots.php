<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('diary_consumption_transitions')
            ->whereIn('action', ['consume', 'correct', 'reconsume'])
            ->whereNull('consumption_snapshot_id')
            ->exists()) {
            throw new RuntimeException('PLAN-06 cannot reconstruct historical nutrition snapshots from current source data. Resolve existing consumption history before migrating.');
        }

        Schema::create('consumption_nutrition_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('diary_consumption_state_id');
            $table->foreign('diary_consumption_state_id', 'consumption_snapshots_state_fk')
                ->references('id')->on('diary_consumption_states')->cascadeOnDelete();
            $table->enum('source_entry_type', ['meal_plan_recipe_entry', 'meal_plan_item_entry', 'diary_entry']);
            $table->unsignedBigInteger('source_entry_id');
            $table->enum('item_kind', ['recipe', 'catalogue', 'one_off']);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->char('source_version_id', 26)->nullable();
            $table->unsignedInteger('source_version_number')->nullable();
            $table->string('nutrition_source', 64)->nullable();
            $table->boolean('is_estimate');
            $table->decimal('actual_amount', 38, 18)->unsigned();
            $table->string('actual_unit', 32);
            $table->json('nutrition')->nullable();
            $table->ulid('audit_event_id')->unique();
            $table->timestamps();

            $table->index(['source_entry_type', 'source_entry_id'], 'consumption_snapshots_entry_index');
            $table->index('source_id');
            $table->index('source_version_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE consumption_nutrition_snapshots
            ADD CONSTRAINT consumption_nutrition_snapshots_payload_check CHECK (
                actual_amount > 0
                AND (
                    (
                        item_kind IN ('recipe', 'catalogue')
                        AND source_id IS NOT NULL
                        AND source_version_id IS NOT NULL
                        AND source_version_number IS NOT NULL
                    ) OR (
                        item_kind = 'one_off'
                        AND source_id IS NULL
                        AND source_version_id IS NULL
                        AND source_version_number IS NULL
                    )
                )
            )
        SQL);

        DB::statement('ALTER TABLE diary_consumption_transitions DROP CHECK diary_consumption_transitions_payload_check');
        DB::statement(<<<'SQL'
            ALTER TABLE diary_consumption_transitions
            ADD CONSTRAINT diary_consumption_transitions_payload_check CHECK (
                (
                    action IN ('consume', 'correct', 'reconsume')
                    AND target_transition_id IS NULL
                    AND actual_amount IS NOT NULL AND actual_amount > 0
                    AND actual_unit IS NOT NULL
                    AND consumed_local_at IS NOT NULL
                    AND timezone IS NOT NULL
                    AND utc_offset_minutes IS NOT NULL
                    AND consumed_at_utc IS NOT NULL
                    AND effective_diary_date IS NOT NULL
                    AND consumption_snapshot_id IS NOT NULL
                ) OR (
                    action = 'reverse'
                    AND target_transition_id IS NOT NULL
                    AND actual_amount IS NULL AND actual_unit IS NULL
                    AND consumed_local_at IS NULL AND timezone IS NULL
                    AND utc_offset_minutes IS NULL AND consumed_at_utc IS NULL
                    AND effective_diary_date IS NULL
                    AND consumption_snapshot_id IS NULL
                )
            )
        SQL);
        Schema::table('diary_consumption_transitions', function (Blueprint $table) {
            $table->foreign('consumption_snapshot_id')->references('id')->on('consumption_nutrition_snapshots')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('consumption_nutrition_snapshots')->exists()) {
            throw new RuntimeException('Consumption nutrition snapshots require a forward migration; rollback refused.');
        }

        Schema::table('diary_consumption_transitions', function (Blueprint $table) {
            $table->dropForeign(['consumption_snapshot_id']);
        });
        DB::statement('ALTER TABLE diary_consumption_transitions DROP CHECK diary_consumption_transitions_payload_check');
        DB::statement(<<<'SQL'
            ALTER TABLE diary_consumption_transitions
            ADD CONSTRAINT diary_consumption_transitions_payload_check CHECK (
                (
                    action IN ('consume', 'correct', 'reconsume')
                    AND target_transition_id IS NULL
                    AND actual_amount IS NOT NULL AND actual_amount > 0
                    AND actual_unit IS NOT NULL
                    AND consumed_local_at IS NOT NULL
                    AND timezone IS NOT NULL
                    AND utc_offset_minutes IS NOT NULL
                    AND consumed_at_utc IS NOT NULL
                    AND effective_diary_date IS NOT NULL
                ) OR (
                    action = 'reverse'
                    AND target_transition_id IS NOT NULL
                    AND actual_amount IS NULL AND actual_unit IS NULL
                    AND consumed_local_at IS NULL AND timezone IS NULL
                    AND utc_offset_minutes IS NULL AND consumed_at_utc IS NULL
                    AND effective_diary_date IS NULL
                    AND consumption_snapshot_id IS NULL
                )
            )
        SQL);
        Schema::drop('consumption_nutrition_snapshots');
    }
};
