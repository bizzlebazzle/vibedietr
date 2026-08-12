<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('administrator_lifecycle_states', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->timestampTz('bootstrap_completed_at')->nullable();
            $table->ulid('bootstrap_audit_event_id')->nullable();
            $table->string('bootstrap_correlation_id', 64)->nullable();
            $table->timestampsTz();
        });
        DB::table('administrator_lifecycle_states')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        Schema::create('administrator_promotion_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('target_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['pending', 'accepted', 'declined', 'cancelled', 'expired']);
            $table->string('correlation_id', 64)->unique();
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('declined_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('expired_at')->nullable();
            $table->timestampsTz();
            $table->index(['target_user_id', 'status'], 'admin_promotion_target_status');
            $table->index(['initiated_by_user_id', 'status'], 'admin_promotion_initiator_status');
        });

        DB::statement("ALTER TABLE audit_events MODIFY action ENUM(
            'administrator.bootstrap_completed', 'administrator.bootstrap_refused',
            'administrator.lifecycle_event', 'catalogue.proposal_approved',
            'recipe.nutrition_override_applied', 'plan.snapshot_recorded',
            'account.anonymization_completed', 'security.second_factor_event',
            'security.notification_event') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE audit_events MODIFY action ENUM(
            'administrator.bootstrap_completed', 'administrator.bootstrap_refused',
            'catalogue.proposal_approved', 'recipe.nutrition_override_applied',
            'plan.snapshot_recorded', 'account.anonymization_completed',
            'security.second_factor_event', 'security.notification_event') NOT NULL");
        Schema::dropIfExists('administrator_promotion_requests');
        Schema::dropIfExists('administrator_lifecycle_states');
    }
};
