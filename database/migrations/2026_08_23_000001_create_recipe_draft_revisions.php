<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_draft_revisions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('recipe_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUlid('base_recipe_version_id')->constrained('recipe_versions')->cascadeOnDelete();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE audit_events MODIFY action ENUM(
            'administrator.bootstrap_completed', 'administrator.bootstrap_refused',
            'administrator.lifecycle_event', 'catalogue.proposal_approved',
            'recipe.finalized', 'recipe.visibility_changed',
            'recipe.revision_created', 'recipe.revision_abandoned', 'recipe.revision_published',
            'recipe.nutrition_override_applied', 'plan.snapshot_recorded',
            'account.anonymization_completed', 'security.second_factor_event',
            'security.notification_event') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE audit_events MODIFY action ENUM(
            'administrator.bootstrap_completed', 'administrator.bootstrap_refused',
            'administrator.lifecycle_event', 'catalogue.proposal_approved',
            'recipe.finalized', 'recipe.visibility_changed',
            'recipe.nutrition_override_applied', 'plan.snapshot_recorded',
            'account.anonymization_completed', 'security.second_factor_event',
            'security.notification_event') NOT NULL");

        Schema::dropIfExists('recipe_draft_revisions');
    }
};
