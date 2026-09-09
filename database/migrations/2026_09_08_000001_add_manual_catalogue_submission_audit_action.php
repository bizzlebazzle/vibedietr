<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE audit_events MODIFY action ENUM(
            'administrator.bootstrap_completed', 'administrator.bootstrap_refused',
            'administrator.lifecycle_event', 'catalogue.proposal_approved',
            'catalogue.manual_submission_created',
            'managed_recipe_vocabulary.changed', 'recipe_tag_suggestion.reviewed',
            'recipe.finalized', 'recipe.visibility_changed',
            'recipe.revision_created', 'recipe.revision_abandoned', 'recipe.revision_published',
            'recipe.remixed', 'recipe.nutrition_override_applied', 'plan.snapshot_recorded',
            'account.anonymization_completed', 'security.second_factor_event',
            'security.notification_event') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE audit_events MODIFY action ENUM(
            'administrator.bootstrap_completed', 'administrator.bootstrap_refused',
            'administrator.lifecycle_event', 'catalogue.proposal_approved',
            'managed_recipe_vocabulary.changed', 'recipe_tag_suggestion.reviewed',
            'recipe.finalized', 'recipe.visibility_changed',
            'recipe.revision_created', 'recipe.revision_abandoned', 'recipe.revision_published',
            'recipe.remixed', 'recipe.nutrition_override_applied', 'plan.snapshot_recorded',
            'account.anonymization_completed', 'security.second_factor_event',
            'security.notification_event') NOT NULL");
    }
};
