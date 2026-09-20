<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_plan_recipe_version_reviews', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('meal_plan_recipe_entry_id');
            $table->foreign('meal_plan_recipe_entry_id', 'plan_recipe_review_entry_fk')
                ->references('id')->on('meal_plan_recipe_entries')->cascadeOnDelete();
            $table->foreignUlid('recipe_version_id');
            $table->foreign('recipe_version_id', 'plan_recipe_review_version_fk')
                ->references('id')->on('recipe_versions')->restrictOnDelete();
            $table->string('correlation_id', 64);
            $table->enum('status', ['pending', 'updated', 'retained', 'inapplicable'])->default('pending');
            $table->ulid('audit_event_id')->nullable()->unique();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['meal_plan_recipe_entry_id', 'recipe_version_id'], 'plan_recipe_version_review_unique');
            $table->index(['meal_plan_recipe_entry_id', 'status'], 'plan_recipe_version_review_pending');
        });

        $this->setAuditActions(true);
    }

    public function down(): void
    {
        if (DB::table('meal_plan_recipe_version_reviews')->exists()
            || DB::table('audit_events')->where('action', 'plan.recipe_version_reviewed')->exists()) {
            throw new RuntimeException('Plan recipe-version review history requires a forward migration; rollback refused.');
        }

        Schema::dropIfExists('meal_plan_recipe_version_reviews');
        $this->setAuditActions(false);
    }

    private function setAuditActions(bool $includeReview): void
    {
        $reviewAction = $includeReview ? ",'plan.recipe_version_reviewed'" : '';
        DB::statement("ALTER TABLE audit_events MODIFY action ENUM(
            'administrator.bootstrap_completed','administrator.bootstrap_refused','administrator.lifecycle_event',
            'catalogue.proposal_approved','catalogue.manual_submission_created',
            'managed_recipe_vocabulary.changed','recipe_tag_suggestion.reviewed',
            'recipe.finalized','recipe.visibility_changed','recipe.revision_created','recipe.revision_abandoned','recipe.revision_published','recipe.remixed',
            'recipe.nutrition_override_applied','recipe.nutrition_recalculated','plan.snapshot_recorded'{$reviewAction},'diary.consumption_transitioned','account.anonymization_completed','security.second_factor_event','security.notification_event',
            'catalogue.candidate_created','catalogue.pending_approved','catalogue.pending_rejected','catalogue.candidate_distinct','catalogue.candidate_duplicate','catalogue.candidate_dismissed',
            'catalogue.merge_applied','catalogue.reference_moved','catalogue.decision_corrected',
            'catalogue.correction_proposed','catalogue.correction_accepted','catalogue.correction_rejected',
            'catalogue.provider_refresh_accepted','catalogue.provider_refresh_rejected') NOT NULL");
    }
};
