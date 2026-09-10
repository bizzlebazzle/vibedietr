<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalogue_provider_refreshes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('catalogue_item_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('base_catalogue_item_version_id')
                ->constrained('catalogue_item_versions', indexName: 'cat_refresh_base_version_fk')->restrictOnDelete();
            $table->enum('provider', ['openfoodfacts']);
            $table->string('source_identifier', 64);
            $table->string('correlation_id', 64);
            $table->enum('state', [
                'queued', 'processing', 'staged', 'no_change', 'not_found',
                'failed', 'cancelled', 'accepted', 'rejected',
            ]);
            $table->string('active_key', 96)->nullable()->unique();
            $table->string('failure_code', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['catalogue_item_id', 'provider', 'state', 'id'], 'cat_refresh_item_provider_state');
            $table->index(['state', 'id'], 'cat_refresh_state_queue');
        });

        Schema::table('catalogue_correction_proposals', function (Blueprint $table) {
            $table->enum('proposal_type', ['user_correction', 'provider_refresh'])
                ->default('user_correction')->after('id')->index();
            $table->foreignUlid('provider_refresh_id')->nullable()->unique()->after('base_catalogue_item_version_id')
                ->constrained('catalogue_provider_refreshes', indexName: 'cat_proposal_provider_refresh_fk')->restrictOnDelete();
        });

        Schema::table('catalogue_correction_changes', function (Blueprint $table) {
            $table->json('provenance')->nullable()->after('proposed_value');
        });

        Schema::table('catalogue_item_versions', function (Blueprint $table) {
            $table->foreignUlid('provider_refresh_id')->nullable()->after('correction_decision_id')
                ->constrained('catalogue_provider_refreshes', indexName: 'cat_version_provider_refresh_fk')->restrictOnDelete();
            $table->json('refreshed_fields')->nullable()->after('corrected_fields');
        });

        Schema::table('catalogue_nutrient_observations', function (Blueprint $table) {
            $table->foreignUlid('provider_refresh_id')->nullable()->after('correction_decision_id')
                ->constrained('catalogue_provider_refreshes', indexName: 'cat_nutr_obs_provider_refresh_fk')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE catalogue_moderation_decisions MODIFY action ENUM(
            'approve','reject','distinct','duplicate','dismiss','merge','correct',
            'correction_accept','correction_reject','provider_refresh_accept','provider_refresh_reject') NOT NULL");

        DB::statement("ALTER TABLE audit_events MODIFY action ENUM(
            'administrator.bootstrap_completed','administrator.bootstrap_refused','administrator.lifecycle_event',
            'catalogue.proposal_approved','catalogue.manual_submission_created',
            'managed_recipe_vocabulary.changed','recipe_tag_suggestion.reviewed',
            'recipe.finalized','recipe.visibility_changed','recipe.revision_created','recipe.revision_abandoned','recipe.revision_published','recipe.remixed',
            'recipe.nutrition_override_applied','plan.snapshot_recorded','account.anonymization_completed','security.second_factor_event','security.notification_event',
            'catalogue.candidate_created','catalogue.pending_approved','catalogue.pending_rejected','catalogue.candidate_distinct','catalogue.candidate_duplicate','catalogue.candidate_dismissed',
            'catalogue.merge_applied','catalogue.reference_moved','catalogue.decision_corrected',
            'catalogue.correction_proposed','catalogue.correction_accepted','catalogue.correction_rejected',
            'catalogue.provider_refresh_accepted','catalogue.provider_refresh_rejected') NOT NULL");
    }

    public function down(): void
    {
        if (Schema::hasTable('catalogue_provider_refreshes') && DB::table('catalogue_provider_refreshes')->exists()) {
            throw new RuntimeException('Provider refresh history requires a forward migration; rollback refused.');
        }

        Schema::table('catalogue_nutrient_observations', function (Blueprint $table) {
            $table->dropForeign('cat_nutr_obs_provider_refresh_fk');
            $table->dropColumn('provider_refresh_id');
        });
        Schema::table('catalogue_item_versions', function (Blueprint $table) {
            $table->dropForeign('cat_version_provider_refresh_fk');
            $table->dropColumn(['provider_refresh_id', 'refreshed_fields']);
        });
        Schema::table('catalogue_correction_changes', fn (Blueprint $table) => $table->dropColumn('provenance'));
        Schema::table('catalogue_correction_proposals', function (Blueprint $table) {
            $table->dropForeign('cat_proposal_provider_refresh_fk');
            $table->dropColumn(['proposal_type', 'provider_refresh_id']);
        });
        Schema::dropIfExists('catalogue_provider_refreshes');
    }
};
