<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->char('account_generation', 26)->nullable()->unique();
        });
        DB::table('users')->orderBy('id')->chunkById(200, function ($users): void {
            foreach ($users as $user) {
                DB::table('users')->where('id', $user->id)->update(['account_generation' => (string) Str::ulid()]);
            }
        });
        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        Schema::create('account_deletions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->char('account_generation', 26)->index();
            $table->string('state', 24);
            $table->timestamp('requested_at');
            $table->timestamp('deadline')->index();
            $table->string('reason', 16)->default('ordinary');
            $table->char('correlation_id', 26);
            $table->char('operation_id', 26);
            $table->longText('recovery_state')->nullable();
            $table->timestamps();
        });
        Schema::create('account_erasure_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->timestamp('completed_at');
        });
        Schema::create('erasure_evidence_holds', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('account_generation', 26)->index();
            $table->string('category', 40);
            $table->longText('protected_evidence');
            $table->string('authority_reference', 128);
            $table->string('operator_reference', 128);
            $table->timestamp('created_at');
            $table->timestamp('review_at')->index();
            $table->timestamp('released_at')->nullable();
        });
        Schema::create('retained_plan_removals', function (Blueprint $table): void {
            $table->unsignedBigInteger('meal_plan_id')->primary();
            $table->string('state', 16);
            $table->timestamp('recorded_at');
        });
        $column = DB::selectOne("SHOW COLUMNS FROM audit_events LIKE 'action'");
        if (str_contains($column->Type, "'account.lifecycle_event'")) {
            return;
        }
        $enum = substr($column->Type, 0, -1).",'account.lifecycle_event')";
        DB::statement("ALTER TABLE audit_events MODIFY action {$enum} NOT NULL");
    }

    public function down(): void
    {
        if (DB::table('account_deletions')->exists() || DB::table('account_erasure_receipts')->exists()
            || DB::table('erasure_evidence_holds')->exists() || DB::table('retained_plan_removals')->exists()
            || DB::table('recipes')->whereNull('user_id')->exists()) {
            throw new RuntimeException('Erasure lifecycle history requires a forward migration.');
        }
        Schema::dropIfExists('retained_plan_removals');
        Schema::dropIfExists('erasure_evidence_holds');
        Schema::dropIfExists('account_erasure_receipts');
        Schema::dropIfExists('account_deletions');
        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('account_generation'));
    }
};
