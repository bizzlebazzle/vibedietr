<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_actor_identities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->enum('identity_type', ['user', 'external_operator', 'deployment']);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_reference', 64)->nullable();

            $table->unique('user_id');
            $table->unique(['identity_type', 'external_reference'], 'audit_identity_external_unique');
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->enum('action', [
                'administrator.bootstrap_completed',
                'administrator.bootstrap_refused',
                'catalogue.proposal_approved',
                'recipe.nutrition_override_applied',
                'plan.snapshot_recorded',
                'account.anonymization_completed',
            ]);
            $table->enum('purpose', [
                'account_security',
                'privileged_access_accountability',
                'moderation_accountability',
                'catalogue_provenance',
                'product_history',
                'account_erasure_evidence',
                'operational_accountability',
            ]);
            $table->enum('retention_class', [
                'security_event_6_months',
                'privileged_identity_12_months',
                'moderation_party_30_days',
                'moderation_decision_12_months',
                'provenance_active_plus_12_months',
                'private_content_until_final_purge',
                'purge_receipt_12_months',
                'operational_evidence_12_months',
            ]);
            $table->enum('actor_type', [
                'authenticated_user',
                'administrator',
                'system',
                'external_operator',
                'deployment',
            ]);
            $table->ulid('actor_identity_id')->nullable()->index();
            $table->enum('subject_type', [
                'user_account',
                'catalogue_proposal',
                'catalogue_item',
                'recipe',
                'nutrition_override',
                'plan_snapshot',
                'system_operation',
            ]);
            $table->ulid('subject_identity_id')->nullable()->index();
            $table->string('subject_identifier', 64)->nullable();
            $table->timestampTz('occurred_at', precision: 6)->index();
            $table->string('correlation_id', 64)->nullable()->index();
            $table->string('evidence_reference', 64)->nullable();
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->json('payload');
            $table->char('integrity_hash', 64);

            $table->index(['purpose', 'occurred_at'], 'audit_events_purpose_time_index');
            $table->index(['retention_class', 'occurred_at'], 'audit_events_retention_time_index');
            $table->index(
                ['subject_type', 'subject_identifier'],
                'audit_events_subject_resource_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('audit_actor_identities');
    }
};
