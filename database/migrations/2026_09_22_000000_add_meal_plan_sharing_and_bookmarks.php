<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_plans', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::table('meal_plans', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->timestamp('retained_unlisted_at')->nullable()->after('visibility');
            $table->timestamp('published_at')->nullable()->after('retained_unlisted_at');
        });
        DB::statement("ALTER TABLE meal_plans MODIFY visibility ENUM('private','public','retained_unlisted') NOT NULL DEFAULT 'private'");
        DB::statement(<<<'SQL'
            ALTER TABLE meal_plans
            ADD CONSTRAINT meal_plans_visibility_owner_check CHECK (
                (`visibility` = 'retained_unlisted' AND user_id IS NULL AND retained_unlisted_at IS NOT NULL)
                OR
                (`visibility` IN ('private', 'public') AND user_id IS NOT NULL AND retained_unlisted_at IS NULL)
            )
        SQL);

        Schema::create('meal_plan_shares', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('meal_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('private_recipe_snapshots_acknowledged_at')->nullable();
            $table->timestamps();

            $table->unique(['meal_plan_id', 'recipient_user_id']);
            $table->index(['recipient_user_id', 'meal_plan_id']);
        });

        Schema::create('meal_plan_bookmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_plan_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'meal_plan_id']);
            $table->index(['meal_plan_id', 'user_id']);
        });

        $this->setAuditEnums(true);
    }

    public function down(): void
    {
        if (DB::table('meal_plan_shares')->exists()
            || DB::table('meal_plan_bookmarks')->exists()
            || DB::table('meal_plans')->whereIn('visibility', ['public', 'retained_unlisted'])->exists()
            || DB::table('audit_events')->whereIn('action', ['plan.sharing_changed', 'plan.bookmark_changed'])->exists()) {
            throw new RuntimeException('Meal-plan sharing and bookmark history requires a forward migration; rollback refused.');
        }

        Schema::dropIfExists('meal_plan_bookmarks');
        Schema::dropIfExists('meal_plan_shares');
        DB::statement('ALTER TABLE meal_plans DROP CHECK meal_plans_visibility_owner_check');
        Schema::table('meal_plans', function (Blueprint $table) {
            $table->dropColumn(['retained_unlisted_at', 'published_at']);
            $table->dropForeign(['user_id']);
        });
        Schema::table('meal_plans', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        DB::statement("ALTER TABLE meal_plans MODIFY visibility ENUM('private') NOT NULL DEFAULT 'private'");
        $this->setAuditEnums(false);
    }

    private function setAuditEnums(bool $includePlanSharing): void
    {
        $planActions = $includePlanSharing
            ? "'plan.snapshot_recorded','plan.recipe_version_reviewed','plan.sharing_changed','plan.bookmark_changed'"
            : "'plan.snapshot_recorded','plan.recipe_version_reviewed'";
        DB::statement("ALTER TABLE audit_events MODIFY action ENUM(
            'administrator.bootstrap_completed','administrator.bootstrap_refused','administrator.lifecycle_event',
            'catalogue.proposal_approved','catalogue.manual_submission_created',
            'managed_recipe_vocabulary.changed','recipe_tag_suggestion.reviewed',
            'recipe.finalized','recipe.visibility_changed','recipe.revision_created','recipe.revision_abandoned','recipe.revision_published','recipe.remixed',
            'recipe.nutrition_override_applied','recipe.nutrition_recalculated',{$planActions},'diary.consumption_transitioned','account.anonymization_completed','security.second_factor_event','security.notification_event',
            'catalogue.candidate_created','catalogue.pending_approved','catalogue.pending_rejected','catalogue.candidate_distinct','catalogue.candidate_duplicate','catalogue.candidate_dismissed',
            'catalogue.merge_applied','catalogue.reference_moved','catalogue.decision_corrected',
            'catalogue.correction_proposed','catalogue.correction_accepted','catalogue.correction_rejected',
            'catalogue.provider_refresh_accepted','catalogue.provider_refresh_rejected') NOT NULL");

        $planSubjects = $includePlanSharing ? "'plan_snapshot', 'meal_plan'" : "'plan_snapshot'";
        DB::statement("ALTER TABLE audit_events MODIFY subject_type ENUM(
            'user_account', 'catalogue_proposal', 'catalogue_item', 'recipe',
            'managed_recipe_term', 'recipe_tag_suggestion', 'nutrition_override', 'nutrition_calculation',
            {$planSubjects}, 'diary_consumption_transition', 'system_operation') NOT NULL");
    }
};
