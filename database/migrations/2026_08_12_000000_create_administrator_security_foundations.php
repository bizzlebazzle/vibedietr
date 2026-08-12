<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('second_factor_enrollments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('encrypted_secret');
            $table->enum('purpose', ['enrollment', 'replacement', 'recovery']);
            $table->unsignedBigInteger('verified_timestep')->nullable();
            $table->timestampTz('recovery_codes_generated_at')->nullable();
            $table->timestampTz('expires_at')->index();
            $table->timestampsTz();

            $table->unique('user_id');
        });

        Schema::create('second_factors', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('encrypted_secret');
            $table->unsignedBigInteger('last_consumed_timestep')->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestampTz('locked_until')->nullable()->index();
            $table->timestampTz('confirmed_at');
            $table->timestampTz('recovery_codes_acknowledged_at');
            $table->timestampsTz();
        });

        Schema::create('second_factor_recovery_codes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('factor_id')->nullable();
            $table->ulid('enrollment_id')->nullable();
            $table->string('code_hash');
            $table->timestampTz('used_at')->nullable();
            $table->timestampsTz();

            $table->foreign('factor_id')->references('id')->on('second_factors')->cascadeOnDelete();
            $table->foreign('enrollment_id')->references('id')->on('second_factor_enrollments')->cascadeOnDelete();
            $table->index(['factor_id', 'used_at']);
            $table->index('enrollment_id');
        });

        Schema::create('second_factor_verification_failures', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->ulid('factor_id')->nullable();
            $table->string('operation', 64);
            $table->char('source_fingerprint', 64);
            $table->timestampTz('occurred_at', precision: 6)->index();

            $table->foreign('factor_id')->references('id')->on('second_factors')->cascadeOnDelete();
            $table->index(['user_id', 'factor_id', 'operation', 'occurred_at'], 'second_factor_failure_scope_index');
            $table->index(['source_fingerprint', 'occurred_at'], 'second_factor_failure_source_index');
        });

        Schema::create('security_notification_intents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 96);
            $table->enum('recipient_category', ['affected_account', 'active_administrator']);
            $table->char('destination_version', 64);
            $table->char('idempotency_key', 64)->unique();
            $table->string('correlation_id', 64)->index();
            $table->string('environment', 32);
            $table->string('application_instance', 64);
            $table->enum('status', ['created', 'queued', 'processing', 'provider_accepted', 'deferred', 'permanently_rejected', 'retry_exhausted']);
            $table->string('provider_reference', 191)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->timestampTz('provider_accepted_at')->nullable();
            $table->timestampTz('terminal_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'created_at']);
            $table->index(['event_type', 'correlation_id']);
        });

        Schema::create('security_notification_health', function (Blueprint $table) {
            $table->id();
            $table->boolean('channel_healthy')->default(false);
            $table->boolean('capacity_available')->default(false);
            $table->boolean('clock_synchronized')->default(false);
            $table->boolean('audit_persistence_healthy')->default(false);
            $table->timestampTz('provider_accepted_at')->nullable();
            $table->timestampTz('capacity_checked_at')->nullable();
            $table->timestampTz('worker_heartbeat_at')->nullable();
            $table->timestampTz('failure_monitor_heartbeat_at')->nullable();
            $table->string('last_failure_code', 64)->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE audit_events MODIFY action ENUM(
            'administrator.bootstrap_completed',
            'administrator.bootstrap_refused',
            'catalogue.proposal_approved',
            'recipe.nutrition_override_applied',
            'plan.snapshot_recorded',
            'account.anonymization_completed',
            'security.second_factor_event',
            'security.notification_event'
        ) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE audit_events MODIFY action ENUM(
            'administrator.bootstrap_completed',
            'administrator.bootstrap_refused',
            'catalogue.proposal_approved',
            'recipe.nutrition_override_applied',
            'plan.snapshot_recorded',
            'account.anonymization_completed'
        ) NOT NULL");

        Schema::dropIfExists('security_notification_health');
        Schema::dropIfExists('security_notification_intents');
        Schema::dropIfExists('second_factor_verification_failures');
        Schema::dropIfExists('second_factor_recovery_codes');
        Schema::dropIfExists('second_factors');
        Schema::dropIfExists('second_factor_enrollments');
    }
};
