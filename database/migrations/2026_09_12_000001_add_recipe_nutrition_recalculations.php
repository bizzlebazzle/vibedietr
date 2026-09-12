<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_nutrition_recalculations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('recipe_version_id')
                ->constrained('recipe_versions', indexName: 'recipe_nutrition_recalc_recipe_fk')
                ->cascadeOnDelete();
            $table->foreignUlid('approved_catalogue_item_version_id')
                ->constrained('catalogue_item_versions', indexName: 'recipe_nutrition_recalc_catalogue_fk')
                ->restrictOnDelete();
            $table->string('correlation_id', 64);
            $table->enum('state', ['queued', 'processing', 'completed', 'skipped', 'failed']);
            $table->json('estimate')->nullable();
            $table->ulid('audit_event_id')->nullable()->unique();
            $table->string('failure_code', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(
                ['recipe_version_id', 'approved_catalogue_item_version_id'],
                'recipe_nutrition_recalc_operation_unique',
            );
            $table->index(
                ['recipe_version_id', 'state', 'completed_at'],
                'recipe_nutrition_recalc_current',
            );
        });

        $this->setAuditActions(includeRecalculation: true);
        $this->setAuditSubjectTypes(includeCalculation: true);
    }

    public function down(): void
    {
        if (DB::table('recipe_nutrition_recalculations')->exists()
            || DB::table('audit_events')->where('action', 'recipe.nutrition_recalculated')->exists()) {
            throw new RuntimeException('Recipe nutrition recalculation history requires a forward migration; rollback refused.');
        }

        Schema::dropIfExists('recipe_nutrition_recalculations');
        $this->setAuditActions(includeRecalculation: false);
        $this->setAuditSubjectTypes(includeCalculation: false);
    }

    private function setAuditActions(bool $includeRecalculation): void
    {
        $nutritionActions = $includeRecalculation
            ? "'recipe.nutrition_override_applied','recipe.nutrition_recalculated'"
            : "'recipe.nutrition_override_applied'";

        DB::statement("ALTER TABLE audit_events MODIFY action ENUM(
            'administrator.bootstrap_completed','administrator.bootstrap_refused','administrator.lifecycle_event',
            'catalogue.proposal_approved','catalogue.manual_submission_created',
            'managed_recipe_vocabulary.changed','recipe_tag_suggestion.reviewed',
            'recipe.finalized','recipe.visibility_changed','recipe.revision_created','recipe.revision_abandoned','recipe.revision_published','recipe.remixed',
            {$nutritionActions},'plan.snapshot_recorded','account.anonymization_completed','security.second_factor_event','security.notification_event',
            'catalogue.candidate_created','catalogue.pending_approved','catalogue.pending_rejected','catalogue.candidate_distinct','catalogue.candidate_duplicate','catalogue.candidate_dismissed',
            'catalogue.merge_applied','catalogue.reference_moved','catalogue.decision_corrected',
            'catalogue.correction_proposed','catalogue.correction_accepted','catalogue.correction_rejected',
            'catalogue.provider_refresh_accepted','catalogue.provider_refresh_rejected') NOT NULL");
    }

    private function setAuditSubjectTypes(bool $includeCalculation): void
    {
        $nutritionSubjects = $includeCalculation
            ? "'nutrition_override', 'nutrition_calculation'"
            : "'nutrition_override'";

        DB::statement("ALTER TABLE audit_events MODIFY subject_type ENUM(
            'user_account', 'catalogue_proposal', 'catalogue_item', 'recipe',
            'managed_recipe_term', 'recipe_tag_suggestion', {$nutritionSubjects},
            'plan_snapshot', 'system_operation') NOT NULL");
    }
};
