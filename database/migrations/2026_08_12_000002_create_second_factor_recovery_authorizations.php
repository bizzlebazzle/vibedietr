<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('second_factor_recovery_authorizations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('target_user_id');
            $table->foreignId('initiated_by_user_id')->nullable();
            $table->foreign('target_user_id', 'recovery_auth_target_foreign')
                ->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('initiated_by_user_id', 'recovery_auth_initiator_foreign')
                ->references('id')->on('users')->nullOnDelete();
            $table->enum('method', ['assisted_administrator', 'deployment_cli']);
            $table->string('authorization_hash')->nullable();
            $table->string('operator_reference', 96)->nullable();
            $table->string('correlation_id', 64)->index();
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->index(['target_user_id', 'consumed_at', 'cancelled_at'], 'recovery_authorization_target_index');
        });

        Schema::table('second_factor_enrollments', function (Blueprint $table) {
            $table->ulid('recovery_authorization_id')->nullable()->after('purpose');
            $table->foreign('recovery_authorization_id', 'enrollment_recovery_authorization_foreign')
                ->references('id')->on('second_factor_recovery_authorizations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('second_factor_enrollments', function (Blueprint $table) {
            $table->dropForeign('enrollment_recovery_authorization_foreign');
            $table->dropColumn('recovery_authorization_id');
        });
        Schema::dropIfExists('second_factor_recovery_authorizations');
    }
};
