<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_recipe_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('normalized_name', 100);
            $table->timestamps();
            $table->unique(['recipe_id', 'normalized_name']);
        });

        Schema::create('managed_recipe_terms', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('category', 32);
            $table->string('name', 100);
            $table->string('normalized_name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['category', 'normalized_name']);
            $table->index(['category', 'is_active']);
        });

        Schema::create('managed_recipe_term_recipes', function (Blueprint $table) {
            $table->foreignUlid('managed_recipe_term_id')->constrained('managed_recipe_terms')->restrictOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['managed_recipe_term_id', 'recipe_id']);
        });

        Schema::create('managed_recipe_term_suggestions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('managed_recipe_term_id')->constrained('managed_recipe_terms')->restrictOnDelete();
            $table->foreignId('suggested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 32);
            $table->string('status', 32);
            $table->string('pending_key', 64)->nullable()->unique();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['recipe_id', 'status']);
        });

        DB::statement("ALTER TABLE audit_events MODIFY action ENUM(
            'administrator.bootstrap_completed', 'administrator.bootstrap_refused',
            'administrator.lifecycle_event', 'catalogue.proposal_approved',
            'managed_recipe_vocabulary.changed', 'recipe_tag_suggestion.reviewed',
            'recipe.finalized', 'recipe.visibility_changed',
            'recipe.revision_created', 'recipe.revision_abandoned', 'recipe.revision_published',
            'recipe.remixed', 'recipe.nutrition_override_applied', 'plan.snapshot_recorded',
            'account.anonymization_completed', 'security.second_factor_event',
            'security.notification_event') NOT NULL");

        DB::statement("ALTER TABLE audit_events MODIFY subject_type ENUM(
            'user_account', 'catalogue_proposal', 'catalogue_item', 'recipe',
            'managed_recipe_term', 'recipe_tag_suggestion', 'nutrition_override',
            'plan_snapshot', 'system_operation') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE audit_events MODIFY subject_type ENUM(
            'user_account', 'catalogue_proposal', 'catalogue_item', 'recipe',
            'nutrition_override', 'plan_snapshot', 'system_operation') NOT NULL");

        DB::statement("ALTER TABLE audit_events MODIFY action ENUM(
            'administrator.bootstrap_completed', 'administrator.bootstrap_refused',
            'administrator.lifecycle_event', 'catalogue.proposal_approved',
            'recipe.finalized', 'recipe.visibility_changed',
            'recipe.revision_created', 'recipe.revision_abandoned', 'recipe.revision_published',
            'recipe.remixed', 'recipe.nutrition_override_applied', 'plan.snapshot_recorded',
            'account.anonymization_completed', 'security.second_factor_event',
            'security.notification_event') NOT NULL");

        Schema::dropIfExists('managed_recipe_term_suggestions');
        Schema::dropIfExists('managed_recipe_term_recipes');
        Schema::dropIfExists('managed_recipe_terms');
        Schema::dropIfExists('public_recipe_tags');
    }
};
