<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalogue_correction_proposals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('catalogue_item_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('base_catalogue_item_version_id')
                ->constrained('catalogue_item_versions', indexName: 'cat_correction_base_version_fk')->restrictOnDelete();
            $table->foreignId('proposer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500);
            $table->enum('state', ['pending', 'accepted', 'rejected'])->index();
            $table->timestamp('submitted_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['catalogue_item_id', 'state', 'id'], 'cat_correction_item_state');
        });

        Schema::create('catalogue_correction_changes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('proposal_id')->constrained('catalogue_correction_proposals')->restrictOnDelete();
            $table->string('field_key', 80);
            $table->json('before_value')->nullable();
            $table->json('proposed_value')->nullable();
            $table->timestamp('created_at');
            $table->unique(['proposal_id', 'field_key'], 'cat_correction_change_unique');
        });

        Schema::table('catalogue_moderation_decisions', function (Blueprint $table) {
            $table->foreignUlid('correction_proposal_id')->nullable()->unique()->after('candidate_id')
                ->constrained('catalogue_correction_proposals', indexName: 'cat_decision_correction_proposal_fk')->restrictOnDelete();
        });

        Schema::table('catalogue_nutrient_observations', function (Blueprint $table) {
            $table->foreignUlid('correction_proposal_id')->nullable()->after('source')
                ->constrained('catalogue_correction_proposals', indexName: 'cat_nutr_obs_correction_proposal_fk')->restrictOnDelete();
            $table->foreignUlid('correction_decision_id')->nullable()->after('correction_proposal_id')
                ->constrained('catalogue_moderation_decisions', indexName: 'cat_nutr_obs_correction_decision_fk')->restrictOnDelete();
        });

        Schema::table('catalogue_item_versions', function (Blueprint $table) {
            $table->foreignUlid('correction_proposal_id')->nullable()->after('catalogue_item_id')
                ->constrained('catalogue_correction_proposals', indexName: 'cat_version_correction_proposal_fk')->restrictOnDelete();
            $table->foreignUlid('correction_decision_id')->nullable()->after('correction_proposal_id')
                ->constrained('catalogue_moderation_decisions', indexName: 'cat_version_correction_decision_fk')->restrictOnDelete();
            $table->json('corrected_fields')->nullable()->after('correction_decision_id');
        });

        DB::statement("ALTER TABLE audit_events MODIFY action ENUM(
            'administrator.bootstrap_completed','administrator.bootstrap_refused','administrator.lifecycle_event',
            'catalogue.proposal_approved','catalogue.manual_submission_created',
            'managed_recipe_vocabulary.changed','recipe_tag_suggestion.reviewed',
            'recipe.finalized','recipe.visibility_changed','recipe.revision_created','recipe.revision_abandoned','recipe.revision_published','recipe.remixed',
            'recipe.nutrition_override_applied','plan.snapshot_recorded','account.anonymization_completed','security.second_factor_event','security.notification_event',
            'catalogue.candidate_created','catalogue.pending_approved','catalogue.pending_rejected','catalogue.candidate_distinct','catalogue.candidate_duplicate','catalogue.candidate_dismissed',
            'catalogue.merge_applied','catalogue.reference_moved','catalogue.decision_corrected',
            'catalogue.correction_proposed','catalogue.correction_accepted','catalogue.correction_rejected') NOT NULL");
    }

    public function down(): void
    {
        if (Schema::hasTable('catalogue_correction_proposals') && DB::table('catalogue_correction_proposals')->exists()) {
            throw new RuntimeException('Catalogue correction history requires a forward migration; rollback refused.');
        }

        Schema::table('catalogue_nutrient_observations', function (Blueprint $table) {
            $table->dropForeign('cat_nutr_obs_correction_decision_fk');
            $table->dropForeign('cat_nutr_obs_correction_proposal_fk');
            $table->dropColumn(['correction_decision_id', 'correction_proposal_id']);
        });
        Schema::table('catalogue_moderation_decisions', function (Blueprint $table) {
            $table->dropForeign('cat_decision_correction_proposal_fk');
            $table->dropColumn('correction_proposal_id');
        });
        Schema::table('catalogue_item_versions', function (Blueprint $table) {
            $table->dropForeign('cat_version_correction_decision_fk');
            $table->dropForeign('cat_version_correction_proposal_fk');
            $table->dropColumn(['correction_decision_id', 'correction_proposal_id', 'corrected_fields']);
        });
        Schema::dropIfExists('catalogue_correction_changes');
        Schema::dropIfExists('catalogue_correction_proposals');
    }
};
