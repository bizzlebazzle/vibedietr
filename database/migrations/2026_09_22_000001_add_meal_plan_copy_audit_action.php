<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->setAuditActionEnum(true);
    }

    public function down(): void
    {
        if (DB::table('audit_events')->where('action', 'plan.copied')->exists()) {
            throw new RuntimeException('Meal-plan copy audit history requires a forward migration; rollback refused.');
        }

        $this->setAuditActionEnum(false);
    }

    private function setAuditActionEnum(bool $includePlanCopied): void
    {
        $planActions = $includePlanCopied
            ? "'plan.snapshot_recorded','plan.recipe_version_reviewed','plan.sharing_changed','plan.bookmark_changed','plan.copied'"
            : "'plan.snapshot_recorded','plan.recipe_version_reviewed','plan.sharing_changed','plan.bookmark_changed'";

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
    }
};
