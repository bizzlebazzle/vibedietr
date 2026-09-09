<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE catalogue_items MODIFY status ENUM('pending','approved','rejected','merged') NOT NULL");
        Schema::table('catalogue_items', function (Blueprint $table) {
            $table->foreignId('canonical_catalogue_item_id')->nullable()->constrained('catalogue_items')->restrictOnDelete();
            $table->unsignedInteger('moderation_revision')->default(0);
            $table->index(['origin', 'status', 'id']);
        });
        DB::statement("ALTER TABLE catalogue_items ADD CONSTRAINT catalogue_redirect_state CHECK ((status = 'merged' AND canonical_catalogue_item_id IS NOT NULL) OR (status <> 'merged' AND canonical_catalogue_item_id IS NULL))");
        Schema::table('catalogue_duplicate_candidates', function (Blueprint $table) {
            $table->unsignedInteger('moderation_revision')->default(0);
            $table->foreignId('canonical_catalogue_item_id')->nullable()->constrained('catalogue_items', indexName: 'candidate_canonical_fk')->restrictOnDelete();
            $table->index(['status', 'id']);
        });
        Schema::create('catalogue_moderation_decisions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('action', 40);
            $table->foreignId('catalogue_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('candidate_id')->nullable()->constrained('catalogue_duplicate_candidates')->restrictOnDelete();
            $table->foreignId('canonical_catalogue_item_id')->nullable()->constrained('catalogue_items', indexName: 'decision_canonical_fk')->restrictOnDelete();
            $table->foreignUlid('corrects_decision_id')->nullable()->unique()->constrained('catalogue_moderation_decisions')->restrictOnDelete();
            $table->ulid('actor_identity_id')->nullable();
            $table->string('reason_code', 40);
            $table->string('note', 500)->nullable();
            $table->json('evidence');
            $table->timestamp('created_at');
            $table->index(['catalogue_item_id', 'created_at'], 'decision_item_time');
            $table->index(['candidate_id', 'created_at'], 'decision_candidate_time');
            $table->index(['action', 'created_at'], 'decision_action_time');
        });
        Schema::create('catalogue_reference_moves', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('decision_id')->constrained('catalogue_moderation_decisions')->restrictOnDelete();
            $table->enum('reference_type', ['recipe_match', 'redirect']);
            $table->unsignedBigInteger('reference_id');
            $table->foreignId('previous_item_id')->constrained('catalogue_items')->restrictOnDelete();
            $table->foreignId('new_item_id')->constrained('catalogue_items')->restrictOnDelete();
            $table->foreignUlid('previous_version_id')->nullable()->constrained('catalogue_item_versions')->restrictOnDelete();
            $table->foreignUlid('new_version_id')->nullable()->constrained('catalogue_item_versions')->restrictOnDelete();
            $table->ulid('change_marker')->nullable();
            $table->timestamp('created_at');
            $table->unique(['decision_id', 'reference_type', 'reference_id'], 'catalogue_move_unique');
        });
        Schema::table('recipe_ingredient_line_matches', function (Blueprint $table) {
            $table->ulid('change_marker')->nullable();
        });
        Schema::table('catalogue_item_aliases', function (Blueprint $table) {
            $table->foreignUlid('merge_decision_id')->nullable()->constrained('catalogue_moderation_decisions')->restrictOnDelete();
            $table->timestamp('disabled_at')->nullable();
        });
        // Existing provenance must not cascade away if a caller attempts deletion.
        foreach ([['catalogue_item_versions', 'catalogue_item_id', 'catalogue_items'], ['catalogue_item_aliases', 'catalogue_item_id', 'catalogue_items'], ['catalogue_duplicate_candidates', 'first_catalogue_item_id', 'catalogue_items'], ['catalogue_duplicate_candidates', 'second_catalogue_item_id', 'catalogue_items']] as [$name, $column, $target]) {
            Schema::table($name, function (Blueprint $table) use ($column, $target) {
                $table->dropForeign([$column]);
                $table->foreign($column)->references('id')->on($target)->restrictOnDelete();
            });
        }
        DB::statement("ALTER TABLE audit_events MODIFY action ENUM(
            'administrator.bootstrap_completed','administrator.bootstrap_refused','administrator.lifecycle_event',
            'catalogue.proposal_approved','catalogue.manual_submission_created',
            'managed_recipe_vocabulary.changed','recipe_tag_suggestion.reviewed',
            'recipe.finalized','recipe.visibility_changed','recipe.revision_created','recipe.revision_abandoned','recipe.revision_published','recipe.remixed',
            'recipe.nutrition_override_applied','plan.snapshot_recorded','account.anonymization_completed','security.second_factor_event','security.notification_event',
            'catalogue.candidate_created','catalogue.pending_approved','catalogue.pending_rejected','catalogue.candidate_distinct','catalogue.candidate_duplicate','catalogue.candidate_dismissed',
            'catalogue.merge_applied','catalogue.reference_moved','catalogue.decision_corrected') NOT NULL");
    }

    public function down(): void
    {
        if (DB::table('catalogue_moderation_decisions')->exists() || DB::table('catalogue_items')->where('status', 'merged')->exists()) {
            throw new RuntimeException('Catalogue moderation history requires a forward migration; rollback refused.');
        }
        Schema::table('catalogue_item_aliases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merge_decision_id');
            $table->dropColumn('disabled_at');
        });
        Schema::table('recipe_ingredient_line_matches', fn (Blueprint $table) => $table->dropColumn('change_marker'));
        Schema::dropIfExists('catalogue_reference_moves');
        Schema::dropIfExists('catalogue_moderation_decisions');
        Schema::table('catalogue_duplicate_candidates', function (Blueprint $table) {
            $table->dropForeign('candidate_canonical_fk');
            $table->dropColumn('canonical_catalogue_item_id');
            $table->dropColumn('moderation_revision');
            $table->dropIndex(['status', 'id']);
        });
        DB::statement('ALTER TABLE catalogue_items DROP CHECK catalogue_redirect_state');
        Schema::table('catalogue_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('canonical_catalogue_item_id');
            $table->dropColumn('moderation_revision');
            $table->dropIndex(['origin', 'status', 'id']);
        });
        DB::statement("ALTER TABLE catalogue_items MODIFY status ENUM('pending','approved','rejected') NOT NULL");
        // Wider audit values and restrictive provenance FKs are safe to retain.
    }
};
