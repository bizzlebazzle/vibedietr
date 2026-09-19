<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->setAuditEnums(true);
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone', 64)->default('UTC')->after('email');
        });

        Schema::create('diary_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['recipe', 'catalogue', 'one_off']);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->char('source_version_id', 26)->nullable();
            $table->unsignedInteger('source_version_number')->nullable();
            $table->json('source_snapshot');
            $table->timestamps();
            $table->index(['user_id', 'kind']);
            $table->index('source_version_id');
        });

        Schema::create('diary_consumption_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_plan_recipe_entry_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('meal_plan_item_entry_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('diary_entry_id')->nullable()->constrained()->cascadeOnDelete();
            $table->char('current_transition_id', 26)->nullable()->index();
            $table->unsignedInteger('next_sequence')->default(1);
            $table->timestamps();
            $table->unique('meal_plan_recipe_entry_id');
            $table->unique('meal_plan_item_entry_id');
            $table->unique('diary_entry_id');
        });

        Schema::create('diary_consumption_transitions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('diary_consumption_state_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->enum('action', ['consume', 'correct', 'reverse', 'reconsume']);
            $table->char('predecessor_id', 26)->nullable()->index();
            $table->char('target_transition_id', 26)->nullable()->index();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->decimal('actual_amount', 38, 18)->nullable()->unsigned();
            $table->string('actual_unit', 32)->nullable();
            $table->dateTime('consumed_local_at')->nullable();
            $table->string('timezone', 64)->nullable();
            $table->smallInteger('utc_offset_minutes')->nullable();
            $table->timestamp('consumed_at_utc')->nullable();
            $table->date('effective_diary_date')->nullable();
            $table->char('consumption_snapshot_id', 26)->nullable()->index();
            $table->ulid('audit_event_id')->unique();
            $table->timestamps();
            $table->unique(['diary_consumption_state_id', 'sequence'], 'diary_consumption_transition_sequence');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE diary_consumption_states
            ADD CONSTRAINT diary_consumption_states_entry_check CHECK (
                (meal_plan_recipe_entry_id IS NOT NULL) +
                (meal_plan_item_entry_id IS NOT NULL) +
                (diary_entry_id IS NOT NULL) = 1
            )
        SQL);
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
    }

    public function down(): void
    {
        if (DB::table('diary_consumption_transitions')->exists()
            || DB::table('audit_events')->where('action', 'diary.consumption_transitioned')->exists()) {
            throw new RuntimeException('Diary consumption history requires a forward migration; rollback refused.');
        }
        Schema::dropIfExists('diary_consumption_transitions');
        Schema::dropIfExists('diary_consumption_states');
        Schema::dropIfExists('diary_entries');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('timezone'));
        $this->setAuditEnums(false);
    }

    private function setAuditEnums(bool $includeDiary): void
    {
        $diaryAction = $includeDiary ? ",'diary.consumption_transitioned'" : '';
        $diarySubject = $includeDiary ? ",'diary_consumption_transition'" : '';
        DB::statement("ALTER TABLE audit_events MODIFY action ENUM(
            'administrator.bootstrap_completed','administrator.bootstrap_refused','administrator.lifecycle_event',
            'catalogue.proposal_approved','catalogue.manual_submission_created',
            'managed_recipe_vocabulary.changed','recipe_tag_suggestion.reviewed',
            'recipe.finalized','recipe.visibility_changed','recipe.revision_created','recipe.revision_abandoned','recipe.revision_published','recipe.remixed',
            'recipe.nutrition_override_applied','recipe.nutrition_recalculated','plan.snapshot_recorded'{$diaryAction},'account.anonymization_completed','security.second_factor_event','security.notification_event',
            'catalogue.candidate_created','catalogue.pending_approved','catalogue.pending_rejected','catalogue.candidate_distinct','catalogue.candidate_duplicate','catalogue.candidate_dismissed',
            'catalogue.merge_applied','catalogue.reference_moved','catalogue.decision_corrected',
            'catalogue.correction_proposed','catalogue.correction_accepted','catalogue.correction_rejected',
            'catalogue.provider_refresh_accepted','catalogue.provider_refresh_rejected') NOT NULL");
        DB::statement("ALTER TABLE audit_events MODIFY subject_type ENUM(
            'user_account','catalogue_proposal','catalogue_item','recipe','managed_recipe_term','recipe_tag_suggestion',
            'nutrition_override','nutrition_calculation','plan_snapshot'{$diarySubject},'system_operation') NOT NULL");
    }
};
