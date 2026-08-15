<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('visibility', 32);
            $table->json('snapshot');
            $table->timestamp('finalized_at');
            $table->timestamps();

            $table->unique(['recipe_id', 'version_number']);
        });

        Schema::table('recipes', function (Blueprint $table) {
            $table->foreignUlid('current_recipe_version_id')
                ->nullable()
                ->after('visibility')
                ->constrained('recipe_versions')
                ->nullOnDelete();
            $table->timestamp('finalized_at')->nullable()->after('current_recipe_version_id');
        });

        DB::statement("ALTER TABLE audit_events MODIFY action ENUM(
            'administrator.bootstrap_completed', 'administrator.bootstrap_refused',
            'administrator.lifecycle_event', 'catalogue.proposal_approved',
            'recipe.finalized', 'recipe.nutrition_override_applied', 'plan.snapshot_recorded',
            'account.anonymization_completed', 'security.second_factor_event',
            'security.notification_event') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE audit_events MODIFY action ENUM(
            'administrator.bootstrap_completed', 'administrator.bootstrap_refused',
            'administrator.lifecycle_event', 'catalogue.proposal_approved',
            'recipe.nutrition_override_applied', 'plan.snapshot_recorded',
            'account.anonymization_completed', 'security.second_factor_event',
            'security.notification_event') NOT NULL");

        Schema::table('recipes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_recipe_version_id');
            $table->dropColumn('finalized_at');
        });

        Schema::dropIfExists('recipe_versions');
    }
};
